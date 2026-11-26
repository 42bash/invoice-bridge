<?php

namespace App\Services\InvoiceNinja;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Invoice Ninja v5 REST API, used with a dedicated token whose user only has
 * the permissions the connector needs (invoices, credits, clients read;
 * vendors and expenses read/create; documents upload).
 */
class InvoiceNinjaClient
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $token,
        private readonly int $timeout = 30,
        private readonly int $pdfTimeout = 180,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('einvoicing.invoice_ninja.url'),
            config('einvoicing.invoice_ninja.token'),
            (int) config('einvoicing.invoice_ninja.timeout', 30),
            (int) config('einvoicing.invoice_ninja.pdf_timeout', 180),
        );
    }

    public function configured(): bool
    {
        return $this->url !== '' && filled($this->token);
    }

    public function ping(): array
    {
        return $this->get('ping');
    }

    public function company(): array
    {
        return $this->get('companies')['data'][0] ?? [];
    }

    /** Invoices or credits updated since a unix timestamp, all pages. */
    public function listUpdated(string $entity, ?int $since = null, array $params = []): array
    {
        $params += ['per_page' => 100, 'sort' => 'id|asc', 'include' => 'client'];

        if ($since) {
            $params['updated_at'] = $since;
        }

        return $this->all($entity, $params);
    }

    public function invoice(string $id): array
    {
        return $this->get("invoices/{$id}", ['include' => 'client,client.contacts,payments'])['data'];
    }

    public function credit(string $id): array
    {
        return $this->get("credits/{$id}", ['include' => 'client,client.contacts'])['data'];
    }

    public function findInvoiceByNumber(string $number): ?array
    {
        return $this->get('invoices', ['number' => $number, 'include' => 'client'])['data'][0] ?? null;
    }

    public function findCreditByNumber(string $number): ?array
    {
        return collect($this->get('credits', ['filter' => $number, 'include' => 'client'])['data'] ?? [])
            ->first(fn (array $c) => ($c['number'] ?? null) === $number);
    }

    public function pdf(string $entity, string $id): string
    {
        // Each attempt makes Invoice Ninja render the PDF again (Chromium): no automatic retry, the queue retries later.
        $response = $this->request(false)->timeout($this->pdfTimeout)->accept('application/pdf')->get("{$entity}/{$id}/download");
        $this->throwUnless($response, "{$entity}/{$id}/download");

        $body = $response->body();

        if (! str_starts_with($body, '%PDF')) {
            throw new RuntimeException("Invoice Ninja n'a pas renvoyé un PDF pour {$entity}/{$id}.");
        }

        return $body;
    }

    public function update(string $entity, string $id, array $data): array
    {
        $response = $this->request()->asJson()->put("{$entity}/{$id}", $data);
        $this->throwUnless($response, "PUT {$entity}/{$id}");

        return $response->json('data') ?? [];
    }

    public function upload(string $entity, string $id, string $filename, string $content, bool $public = false): array
    {
        // Not retried: a lost response may hide a file the server already stored.
        $response = $this->request(retry: false)
            ->attach('documents[]', $content, $filename)
            ->post("{$entity}/{$id}/upload", ['_method' => 'PUT', 'is_public' => $public ? 'true' : 'false']);
        $this->throwUnless($response, "upload {$entity}/{$id}");

        return $response->json('data') ?? [];
    }

    /**
     * Documents already attached to an invoice, credit or expense.
     * Used so a repeated sync, a retry or a lost upload response does not add the file twice.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(string $entity, string $id): array
    {
        return array_values($this->get("{$entity}/{$id}")['data']['documents'] ?? []);
    }

    public function searchVendors(string $filter): array
    {
        return $this->get('vendors', ['filter' => $filter, 'per_page' => 50, 'status' => 'active,archived'])['data'] ?? [];
    }

    public function allVendors(): array
    {
        return $this->all('vendors', ['per_page' => 100, 'status' => 'active,archived']);
    }

    public function createVendor(array $data): array
    {
        $response = $this->request(retry: false)->asJson()->post('vendors', $data);
        $this->throwUnless($response, 'POST vendors');

        return $response->json('data');
    }

    public function expensesForVendor(string $vendorId): array
    {
        return $this->all('expenses', ['vendor_id' => $vendorId, 'per_page' => 100, 'status' => 'active,archived']);
    }

    public function searchExpenses(string $filter): array
    {
        return $this->get('expenses', ['filter' => $filter, 'per_page' => 50, 'status' => 'active,archived'])['data'] ?? [];
    }

    public function createExpense(array $data): array
    {
        $response = $this->request(retry: false)->asJson()->post('expenses', $data);
        $this->throwUnless($response, 'POST expenses');

        return $response->json('data');
    }

    public function expenseCategories(): array
    {
        return $this->get('expense_categories', ['per_page' => 100])['data'] ?? [];
    }

    /** Adds a line to the activity history shown on the invoice, credit or expense. */
    public function addNote(string $entity, string $id, string $notes): array
    {
        return $this->post('activities/notes', ['entity' => $entity, 'entity_id' => $id, 'notes' => $notes]);
    }

    /** Not retried: a lost answer may hide a created record. */
    public function post(string $path, array $data): array
    {
        $response = $this->request(retry: false)->asJson()->post($path, $data);
        $this->throwUnless($response, "POST {$path}");

        return $response->json('data') ?? [];
    }

    public function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($path, $query);
        $this->throwUnless($response, "GET {$path}");

        return $response->json() ?? [];
    }

    private function all(string $path, array $query): array
    {
        $items = [];
        $page = 1;

        do {
            $data = $this->get($path, $query + ['page' => $page]);
            array_push($items, ...($data['data'] ?? []));
            $last = (int) ($data['meta']['pagination']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $last && $page < 100);

        return $items;
    }

    /** Creations are not retried transparently: callers look for an existing record first. */
    private function request(bool $retry = true): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('Invoice Ninja n\'est pas configuré (INVOICE_NINJA_URL / INVOICE_NINJA_API_TOKEN).');
        }

        $request = Http::baseUrl($this->url.'/api/v1')
            ->withHeaders([
                'X-API-TOKEN' => $this->token,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(10);

        if ($retry) {
            $request->retry(3, fn (int $attempt) => $attempt * 1000, function ($e) {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429));
            }, throw: false);
        }

        return $request;
    }

    private function throwUnless(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message') ?? mb_strimwidth(trim(strip_tags($response->body())), 0, 300, '…');
        $errors = $response->json('errors');

        if (is_array($errors)) {
            $message .= ' '.json_encode($errors, JSON_UNESCAPED_UNICODE);
        }

        throw new InvoiceNinjaException("Invoice Ninja {$what} : HTTP {$response->status()} {$message}", $response->status());
    }
}
