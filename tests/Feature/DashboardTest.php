<?php

namespace Tests\Feature;

use App\Models\OutboundDocument;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureConnector([
            'einvoicing.dashboard.user' => 'admin',
            'einvoicing.dashboard.password_hash' => password_hash('correct horse', PASSWORD_BCRYPT),
            'einvoicing.webhook_secret' => 'whsec-test',
        ]);
    }

    private function auth(string $password = 'correct horse'): array
    {
        return ['Authorization' => 'Basic '.base64_encode("admin:{$password}")];
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->get('/', $this->auth('wrong'))->assertStatus(401);
        $this->get('/', $this->auth())->assertOk()->assertSee('Tableau de bord')->assertSee('SANDBOX');
    }

    public function test_dashboard_is_closed_without_password_hash(): void
    {
        config(['einvoicing.dashboard.password_hash' => null]);

        $this->get('/', $this->auth())->assertStatus(503);
    }

    public function test_brute_force_is_throttled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/', $this->auth('wrong'));
        }

        $this->get('/', $this->auth())->assertStatus(429);
    }

    public function test_sending_requires_explicit_confirmation(): void
    {
        $document = OutboundDocument::query()->create(['entity_type' => 'invoice', 'in_id' => 'inv1', 'number' => 'FA-1', 'external_id' => 'in-invoice-inv1', 'state' => OutboundDocument::READY]);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/emises/{$document->id}/transmettre", [], $this->auth())
            ->assertSessionHasErrors('confirm');
    }

    public function test_webhook_requires_the_shared_secret(): void
    {
        $this->fakeHttp();

        $this->postJson('/webhooks/invoiceninja', $this->inInvoice())->assertStatus(401);
        $this->postJson('/webhooks/invoiceninja', $this->inInvoice(), ['X-Einvoicing-Secret' => 'nope'])->assertStatus(401);
        $this->postJson('/webhooks/invoiceninja', $this->inInvoice(), ['X-Einvoicing-Secret' => 'whsec-test'])->assertStatus(202)->assertJson(['recorded' => true]);

        $this->assertSame(1, OutboundDocument::query()->count());
    }

    public function test_transmission_requested_in_invoice_ninja_is_handled_at_once_and_stays_local_while_emission_is_locked(): void
    {
        config(['einvoicing.send_enabled' => false]);
        $this->fakeHttp();

        $this->postJson('/webhooks/invoiceninja', $this->inInvoice(['custom_value1' => 'Transmettre à SuperPDP']), ['X-Einvoicing-Secret' => 'whsec-test'])->assertStatus(202);

        $document = OutboundDocument::query()->sole();
        $this->assertSame(OutboundDocument::READY, $document->state, implode(' | ', (array) $document->errors));
        $this->assertNull($document->superpdp_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/v1.beta/invoices?'));

        $put = collect($this->sentRequests('PUT', '/invoices/inv1'))->last()[0]->data();
        $this->assertStringContainsString('Prête', $put['custom_value2']);
        $this->assertStringContainsString('Émission réelle verrouillée', $put['custom_value4']);
    }
}
