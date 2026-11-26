<?php

namespace App\Services\SuperPdp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SUPER PDP API v1.beta, OAuth 2.1 client credentials.
 *
 * The same base URL serves sandbox and production: the credentials decide.
 * `assertEnvironment()` compares GET /companies/me with SUPERPDP_ENVIRONMENT
 * and every write goes through it, so sandbox credentials can never be used
 * when production is expected, and the reverse.
 *
 * The converter and validator also work without credentials.
 */
class SuperPdpClient
{
    private const TOKEN_CACHE = 'superpdp.access_token';

    public function __construct(
        private readonly string $url,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly string $environment = 'sandbox',
        private readonly int $timeout = 60,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('einvoicing.superpdp.api_url'),
            config('einvoicing.superpdp.client_id'),
            config('einvoicing.superpdp.client_secret'),
            (string) config('einvoicing.superpdp.environment', 'sandbox'),
            (int) config('einvoicing.superpdp.timeout', 60),
        );
    }

    public function configured(): bool
    {
        return filled($this->clientId) && filled($this->clientSecret);
    }

    public function environment(): string
    {
        return $this->environment;
    }

    // --- Account ----------------------------------------------------------------

    public function session(): array
    {
        return $this->json('GET', '/v1.beta/oauth2_sessions/me');
    }

    public function me(bool $fresh = false): array
    {
        $key = 'superpdp.me.'.md5((string) $this->clientId);

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHour(), fn () => $this->json('GET', '/v1.beta/companies/me'));
    }

    /** monthly | quarterly | simplified | vat_exemption: drives the e-reporting schedule. */
    public function updateVatRegime(string $regime): array
    {
        $company = $this->json('PATCH', '/v1.beta/companies', body: ['vat_regime' => $regime], retry: false);
        Cache::forget('superpdp.me.'.md5((string) $this->clientId));

        return $company;
    }

    public function ownDirectoryEntries(): array
    {
        return $this->json('GET', '/v1.beta/directory_entries')['data'] ?? [];
    }

    /** Refuses to continue when the credentials belong to the other environment. */
    public function assertEnvironment(): array
    {
        $me = $this->me();
        $actual = $me['env'] ?? 'unknown';

        if ($actual !== $this->environment) {
            throw new SuperPdpException("Les identifiants SUPER PDP sont ceux de l'environnement « {$actual} » alors que SUPERPDP_ENVIRONMENT vaut « {$this->environment} ». Opération refusée.");
        }

        return $me;
    }

    // --- Public tools (no credentials needed) ------------------------------------

    /** French directory entries of a SIREN; public endpoint. */
    public function frenchDirectoryEntries(string $siren): array
    {
        return $this->json('GET', '/v1.beta/french_directory/entries', ['number' => $siren], auth: false)['data'] ?? [];
    }

    /** EN16931 JSON + readable PDF → Factur-X PDF. */
    public function convertToFacturX(array $invoice, string $pdf, string $pdfName = 'invoice.pdf'): string
    {
        $response = $this->request($this->configured())
            ->attach('invoice', json_encode($invoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'invoice.json', ['Content-Type' => 'application/json'])
            ->attach('pdf', $pdf, $pdfName, ['Content-Type' => 'application/pdf'])
            ->post('/v1.beta/invoices/convert?from=en16931&to=factur-x');

        $this->throwUnless($response, 'convert en16931 → factur-x');

        return $response->body();
    }

    public function convert(array $invoice, string $to): string
    {
        $response = $this->request($this->configured())
            ->withBody(json_encode($invoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json')
            ->post("/v1.beta/invoices/convert?from=en16931&to={$to}");

        $this->throwUnless($response, "convert en16931 → {$to}");

        return $response->body();
    }

    /** Runs EN16931 + French (BR-FR) schematrons; returns the report of the file. */
    public function validate(string $content, string $filename, string $mime = 'application/pdf'): array
    {
        $response = $this->request($this->configured())
            ->attach('file_name', $content, $filename, ['Content-Type' => $mime])
            ->post('/v1.beta/validation_reports');

        $this->throwUnless($response, 'validation_reports');

        return $response->json('data.0') ?? [];
    }

    // --- Invoices -----------------------------------------------------------------

    public function sendInvoice(string $facturx, string $externalId, ?string $processingRule = null, string $mime = 'application/pdf'): array
    {
        $this->assertEnvironment();

        $query = array_filter(['external_id' => $externalId, 'processing_rule' => $processingRule]);

        // No transparent retry: a lost response may hide a successful upload.
        // The caller looks the upload up by external_id before trying again.
        $response = $this->request(retry: false)
            ->withBody($facturx, $mime)
            ->post('/v1.beta/invoices?'.http_build_query($query));

        $this->throwUnless($response, 'POST invoices');

        return $response->json();
    }

    /** One page of invoices with an id greater than $after. */
    public function invoices(string $direction, int $after = 0, int $limit = 100): array
    {
        $query = ['direction' => $direction, 'limit' => $limit, 'order' => 'asc'];

        if ($after > 0) {
            $query['starting_after_id'] = $after;
        }

        return $this->json('GET', '/v1.beta/invoices', $query);
    }

    /** Most recent outgoing invoices, used to find an upload by external_id after a crash. */
    public function recentOutgoing(int $limit = 200): array
    {
        return $this->recent('out', $limit);
    }

    public function recent(string $direction, int $limit = 20, bool $withEvents = false): array
    {
        $query = http_build_query(['direction' => $direction, 'order' => 'desc', 'limit' => $limit]).($withEvents ? '&expand[]=events&expand[]=en_invoice' : '');

        return $this->json('GET', '/v1.beta/invoices?'.$query)['data'] ?? [];
    }

    /** Sandbox only: a valid invoice from this company to another sandbox company, not stored by SUPER PDP. */
    public function generateTestInvoice(string $format = 'en16931'): array
    {
        $this->assertEnvironment();

        if ($this->environment !== 'sandbox') {
            throw new SuperPdpException('generate_test_invoice n\'existe qu\'en bac à sable.');
        }

        return $this->json('GET', '/v1.beta/invoices/generate_test_invoice', ['format' => $format]);
    }

    public function invoice(int $id): array
    {
        return $this->json('GET', "/v1.beta/invoices/{$id}");
    }

    /** Raw file: `original` (as received), `factur-x` (readable PDF), `cii`, `ubl`. */
    public function invoiceFile(int $id, string $format): Response
    {
        $response = $this->request()->accept('*/*')->get("/v1.beta/invoices/{$id}", ['format' => $format]);
        $this->throwUnless($response, "GET invoices/{$id}?format={$format}");

        return $response;
    }

    /**
     * XML (flows 10.1 to 10.4) of the e-reporting not yet sent for the period containing $date,
     * as SUPER PDP will aggregate it; null when there is nothing for that period.
     */
    public function ereportingPreview(string $kind, string $roleCode, string $date): ?string
    {
        $response = $this->request()->accept('*/*')->get('/v1.beta/ereportings/preview', ['kind' => $kind, 'role_code' => $roleCode, 'date' => $date]);

        if ($response->status() === 204) {
            return null;
        }

        $this->throwUnless($response, "GET ereportings/preview?kind={$kind}");

        return $response->body();
    }

    public function events(int $after = 0, int $limit = 500): array
    {
        $query = ['limit' => $limit];

        if ($after > 0) {
            $query['starting_after_id'] = $after;
        }

        return $this->json('GET', '/v1.beta/invoice_events', $query);
    }

    public function createEvent(int $invoiceId, string $statusCode, array $details = []): array
    {
        $this->assertEnvironment();

        $payload = ['invoice_id' => $invoiceId, 'status_code' => $statusCode];

        if ($details) {
            $payload['details'] = $details;
        }

        return $this->json('POST', '/v1.beta/invoice_events', body: $payload, retry: false);
    }

    // --- Plumbing -----------------------------------------------------------------

    public function token(bool $fresh = false): string
    {
        if (! $this->configured()) {
            throw new SuperPdpException('SUPER PDP n\'est pas configuré (SUPERPDP_CLIENT_ID / SUPERPDP_CLIENT_SECRET).');
        }

        $key = self::TOKEN_CACHE.'.'.md5((string) $this->clientId);

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout(30)
            ->retry(3, fn (int $attempt) => $attempt * 1000, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->post($this->url.'/oauth2/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        if ($response->serverError() || $response->status() === 429) {
            throw new SuperPdpException("SUPER PDP indisponible lors de l'authentification (HTTP {$response->status()}).", $response->status());
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new SuperPdpException("Authentification SUPER PDP refusée (HTTP {$response->status()}). Vérifiez SUPERPDP_CLIENT_ID / SUPERPDP_CLIENT_SECRET.", $response->status(), authentication: true);
        }

        $ttl = max(60, (int) ($response->json('expires_in') ?? 1800) - 120);
        Cache::put($key, $response->json('access_token'), now()->addSeconds($ttl));

        return $response->json('access_token');
    }

    private function json(string $method, string $path, array $query = [], ?array $body = null, bool $auth = true, bool $retry = true): array
    {
        $send = function (bool $freshToken) use ($method, $path, $query, $body, $auth, $retry) {
            $request = $this->request($auth, $freshToken, $retry);

            return match ($method) {
                'GET' => $query ? $request->get($path, $query) : $request->get($path),
                'POST' => $request->asJson()->post($path.($query ? '?'.http_build_query($query) : ''), $body ?? []),
                'PATCH' => $request->asJson()->patch($path, $body ?? []),
                'DELETE' => $request->delete($path),
                default => throw new \UnhandledMatchError($method),
            };
        };

        $response = $send(false);

        // Expired or revoked access token: get a new one once and replay.
        if ($auth && $response->status() === 401) {
            $response = $send(true);
        }

        $this->throwUnless($response, "{$method} {$path}");

        return $response->json() ?? [];
    }

    private function request(bool $auth = true, bool $freshToken = false, bool $retry = true): PendingRequest
    {
        $request = Http::baseUrl($this->url)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(10);

        if ($retry) {
            $request->retry(3, fn (int $attempt) => $attempt * 2000, function ($e) {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429));
            }, throw: false);
        }

        if ($auth) {
            $request->withToken($this->token($freshToken));
        }

        return $request;
    }

    private function throwUnless(Response $response, string $what): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message') ?? mb_strimwidth(trim($response->body()), 0, 500, '…');

        throw new SuperPdpException("SUPER PDP {$what} : HTTP {$response->status()} {$message}", $response->status(), $response->json('code'));
    }
}
