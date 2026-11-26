<?php

namespace Tests\Feature;

use App\Models\ExchangeLog;
use App\Models\InboundDocument;
use App\Models\SyncCursor;
use App\Services\Notifier;
use App\Services\SuperPdp\SuperPdpClient;
use App\Services\Supervisor;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;
use Tests\TestCase;

class SupervisorTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // SUPER PDP side only, unless a test configures Invoice Ninja.
        $this->configureConnector(['einvoicing.invoice_ninja.url' => '', 'einvoicing.notify.webhook_url' => null, 'einvoicing.notify.mail_to' => null]);
    }

    private function alerts(string $kind): int
    {
        return ExchangeLog::query()->where('channel', 'notify')->where('action', $kind)->count();
    }

    private function sync(): void
    {
        app()->forgetInstance(SuperPdpClient::class);
        app(SyncService::class)->run();
    }

    /** SUPER PDP simulation whose behaviour changes during the test: ok, auth, down. */
    private function fakeSuperPdp(string &$mode): void
    {
        Http::fake(function (Request $request) use (&$mode) {
            if (str_ends_with($request->url(), '/oauth2/token')) {
                return $mode === 'auth' ? Http::response(['error' => 'invalid_client'], 401) : Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }

            return $mode === 'down' ? Http::response('Service Unavailable', 503) : Http::response(['data' => [], 'has_after' => false]);
        });
    }

    public function test_refused_credentials_raise_one_alert_then_a_recovery_message(): void
    {
        $mode = 'auth';
        $this->fakeSuperPdp($mode);

        $this->sync();
        $this->sync();

        $this->assertSame(1, $this->alerts('credentials'));
        $this->assertStringContainsString('SUPERPDP_CLIENT_SECRET', ExchangeLog::query()->where('channel', 'notify')->where('action', 'credentials')->value('message'));

        $mode = 'ok';
        $this->sync();

        $this->assertSame(1, $this->alerts('recovered'));
    }

    public function test_superpdp_outage_is_reported_only_when_it_lasts(): void
    {
        $mode = 'down';
        $this->fakeSuperPdp($mode);

        $this->sync();
        $this->assertSame(0, $this->alerts('outage'), 'a short outage is absorbed by the next passes');
        $this->assertSame(0, $this->alerts('sync_failed'), 'an outage is not also reported as a sync failure');

        $this->travel(61)->minutes();
        $this->sync();
        $this->travel(10)->minutes();
        $this->sync();
        $this->assertSame(1, $this->alerts('outage'));

        $mode = 'ok';
        $this->sync();

        $this->assertSame(1, $this->alerts('recovered'));
        $this->assertNull(SyncCursor::get('health.superpdp_down_since'));
    }

    public function test_repeated_sync_failure_is_reported_after_consecutive_passes(): void
    {
        $this->configureConnector(['einvoicing.superpdp.client_id' => null, 'einvoicing.notify.webhook_url' => null, 'einvoicing.notify.mail_to' => null]);
        Http::fake([self::IN.'/*' => Http::response(['message' => 'Server Error'], 500)]);

        app(SyncService::class)->run();
        $this->assertSame(0, $this->alerts('sync_failed'));

        app(SyncService::class)->run();
        $this->assertSame(1, $this->alerts('sync_failed'));

        app(SyncService::class)->run();
        $this->assertSame(1, $this->alerts('sync_failed'), 'no repeat within the cooldown');
    }

    public function test_new_supplier_invoices_are_announced_in_one_message_without_duplicates(): void
    {
        $make = fn (int $id, string $state) => InboundDocument::query()->create([
            'superpdp_id' => $id, 'seller_name' => 'Fournisseur Test', 'number' => "F-{$id}", 'issue_date' => '2026-11-20',
            'total_with_vat' => 120, 'currency' => 'EUR', 'state' => $state, 'fingerprint' => "fp{$id}", 'files' => [],
        ]);

        app(Supervisor::class)->received(collect([$make(1, InboundDocument::IMPORTED), $make(2, InboundDocument::DUPLICATE)]));

        $this->assertSame(1, $this->alerts('received'));
        $message = ExchangeLog::query()->where('action', 'received')->where('channel', 'notify')->value('message');
        $this->assertStringContainsString('F-1', $message);
        $this->assertStringNotContainsString('F-2', $message);
    }

    public function test_webhook_and_mail_channels_receive_the_alert_without_secrets(): void
    {
        config(['einvoicing.notify.webhook_url' => 'https://ntfy.test/facturation', 'einvoicing.notify.mail_to' => 'dirigeant@example.test', 'mail.default' => 'array']);
        Http::fake(['https://ntfy.test/*' => Http::response('ok')]);

        app(Notifier::class)->notify('rejected', 'Facture FA-1 rejetée', 'Motif : doublon. Authorization: Bearer abc.def.ghi');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://ntfy.test/facturation'
            && str_contains($r->header('Title')[0], 'Facture FA-1 rejetee')
            && str_contains($r->body(), 'Bearer ***')
            && ! str_contains($r->body(), 'abc.def.ghi'));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('dirigeant@example.test', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString('Facture FA-1 rejetée', $messages[0]->getOriginalMessage()->getSubject());
    }

    public function test_muted_alert_kinds_are_not_sent(): void
    {
        config(['einvoicing.notify.events' => 'rejected']);

        $this->assertFalse(app(Notifier::class)->notify('received', 'x', 'y'));
        $this->assertTrue(app(Notifier::class)->notify('rejected', 'x', 'y'));
    }
}
