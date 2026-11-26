<?php

namespace App\Services;

use App\Services\SuperPdp\SuperPdpClient;
use App\Services\SuperPdp\SuperPdpException;
use App\Support\Archive;
use App\Support\Journal;

/**
 * The second SUPER PDP sandbox company (Tricatel) seen from the outside: it
 * sends invoices to our company and answers the invoices we send it, so both
 * directions can be tested end to end. Every call checks that both sets of
 * credentials belong to the sandbox; nothing here can run in production.
 */
class SandboxPeer
{
    private const FOLDER = 'sandbox-peer';

    public const RESPONSES = [
        'fr:204' => 'Prise en charge',
        'fr:205' => 'Approuvée',
        'fr:206' => 'Approuvée partiellement',
        'fr:207' => 'En litige',
        'fr:208' => 'Suspendue',
        'fr:210' => 'Refusée',
        'fr:211' => 'Paiement transmis',
        'fr:212' => 'Encaissée',
    ];

    private ?SuperPdpClient $peer = null;

    public function __construct(private readonly SuperPdpClient $me) {}

    /** @return array{me: array, peer: array} */
    public function guard(): array
    {
        if (config('einvoicing.superpdp.environment') !== 'sandbox' || $this->me->environment() !== 'sandbox') {
            throw new SuperPdpException('Le partenaire de test ne fonctionne qu\'avec SUPERPDP_ENVIRONMENT=sandbox.');
        }

        $me = $this->me->assertEnvironment();
        $peer = $this->peer()->assertEnvironment();

        if (($me['id'] ?? null) === ($peer['id'] ?? null)) {
            throw new SuperPdpException('SUPERPDP_PEER_CLIENT_ID désigne la même entreprise que SUPERPDP_CLIENT_ID : créez l\'application du partenaire sur l\'autre entreprise de test.');
        }

        return ['me' => $me, 'peer' => $peer];
    }

    /**
     * The peer issues an invoice (or a credit note) to our company.
     *
     * @return array{number: string, superpdp_id: ?int, validation: array, file: array}
     */
    public function sendToMe(?string $number = null, ?string $creditFor = null): array
    {
        ['me' => $me, 'peer' => $peer] = $this->guard();
        $invoice = $this->peer()->generateTestInvoice('en16931');
        $number ??= 'TRI-'.now()->format('YmdHis');

        $invoice['number'] = $number;
        $invoice['issue_date'] = now()->toDateString();
        $invoice['buyer'] = array_filter([
            'name' => $me['formal_name'] ?? 'Entreprise de test',
            'postal_address' => $invoice['buyer']['postal_address'] ?? ['country_code' => 'FR'],
            'legal_registration_identifier' => ['scheme' => '0002', 'value' => (string) $me['number']],
            'electronic_address' => $this->myAddress($me),
        ]);

        if ($creditFor !== null) {
            $invoice['type_code'] = 381;
            $invoice['preceding_invoice_references'] = [['reference' => $creditFor, 'issue_date' => now()->toDateString()]];
        }

        $cii = $this->peer()->convert($invoice, 'cii');
        $validation = $this->peer()->validate($cii, "{$number}.xml", 'application/xml');
        $file = Archive::put(self::FOLDER, "{$number}.xml", $cii, 'application/xml');
        $sent = $this->peer()->sendInvoice($cii, "peer-{$number}", null, 'application/xml');

        Journal::info('sandbox', 'peer_sent', ($creditFor ? 'Avoir' : 'Facture')." de test {$number} envoyée par {$peer['formal_name']} à {$me['formal_name']}.", null, ['superpdp_id' => $sent['id'] ?? null]);

        return ['number' => $number, 'superpdp_id' => $sent['id'] ?? null, 'validation' => $validation, 'file' => $file];
    }

    /** Sends the very same file again under a new external_id, to test duplicate detection on reception. */
    public function resend(string $number): array
    {
        $this->guard();
        $entry = ['path' => self::FOLDER.'/'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $number).'.xml'];
        $content = Archive::get($entry);
        $sent = $this->peer()->sendInvoice($content, "peer-{$number}-r".now()->format('His'), null, 'application/xml');

        Journal::info('sandbox', 'peer_resent', "Facture de test {$number} renvoyée à l'identique (test de doublon).", null, ['superpdp_id' => $sent['id'] ?? null]);

        return $sent;
    }

    /** Invoices received by the peer, i.e. the ones our company sent. */
    public function inbox(int $limit = 20): array
    {
        $this->guard();

        return $this->peer()->recent('in', $limit, true);
    }

    /** The peer answers an invoice it received (lifecycle status sent back to us). */
    public function respond(int $invoiceId, string $code, ?string $reason = null, ?string $note = null): array
    {
        if (! array_key_exists($code, self::RESPONSES)) {
            throw new SuperPdpException("Statut non prévu pour le partenaire : {$code}");
        }

        $this->guard();
        $details = filled($reason) || filled($note)
            ? [array_filter(['reason' => $reason, 'notes' => filled($note) ? [['subject' => 'AAI', 'contents' => [['content' => $note]]]] : null])]
            : [];
        $event = $this->peer()->createEvent($invoiceId, $code, $details);

        Journal::info('sandbox', 'peer_status', "Le partenaire de test a répondu {$code} « ".self::RESPONSES[$code]." » à la facture SuperPDP #{$invoiceId}.", null, ['event_id' => $event['id'] ?? null]);

        return $event;
    }

    private function peer(): SuperPdpClient
    {
        if ($this->peer) {
            return $this->peer;
        }

        $id = config('einvoicing.superpdp.peer_client_id');
        $secret = config('einvoicing.superpdp.peer_client_secret');

        if (blank($id) || blank($secret)) {
            throw new SuperPdpException('Partenaire de test non configuré : renseignez SUPERPDP_PEER_CLIENT_ID et SUPERPDP_PEER_CLIENT_SECRET (application de l\'autre entreprise du bac à sable).');
        }

        return $this->peer = new SuperPdpClient((string) config('einvoicing.superpdp.api_url'), $id, $secret, 'sandbox', (int) config('einvoicing.superpdp.timeout', 60));
    }

    /** @return array{me: array{scheme: string, value: string}, peer: array{scheme: string, value: string}} */
    public function addresses(): array
    {
        ['me' => $me] = $this->guard();

        return ['me' => $this->myAddress($me), 'peer' => $this->addressOf($this->peer(), $this->guard()['peer'])];
    }

    private function addressOf(SuperPdpClient $client, array $company): array
    {
        foreach ($client->ownDirectoryEntries() as $entry) {
            $identifier = (string) ($entry['identifier'] ?? '');

            if (($entry['status'] ?? 'created') !== 'created' || ! empty($entry['is_replyto']) || $identifier === '') {
                continue;
            }

            [$scheme, $value] = str_contains($identifier, ':') ? explode(':', $identifier, 2) : ['0225', $identifier];

            return ['scheme' => $scheme, 'value' => $value];
        }

        return ['scheme' => '0225', 'value' => (string) $company['number']];
    }

    private function myAddress(array $me): array
    {
        foreach ($this->me->ownDirectoryEntries() as $entry) {
            $identifier = (string) ($entry['identifier'] ?? '');

            if (($entry['status'] ?? 'created') !== 'created' || ! empty($entry['is_replyto']) || $identifier === '') {
                continue;
            }

            [$scheme, $value] = str_contains($identifier, ':') ? explode(':', $identifier, 2) : ['0225', $identifier];

            return ['scheme' => $scheme, 'value' => $value];
        }

        return ['scheme' => '0225', 'value' => (string) $me['number']];
    }
}
