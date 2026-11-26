<?php

namespace Tests\Feature;

use App\Services\SuperPdp\SuperPdpClient;
use App\Services\SuperPdp\SuperPdpException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;
use Tests\TestCase;

class SuperPdpClientTest extends TestCase
{
    use Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureConnector();
    }

    public function test_token_is_cached_and_refreshed_once_on_401(): void
    {
        Http::fake([
            self::SPDP.'/oauth2/token' => Http::sequence()
                ->push(['access_token' => 'old', 'expires_in' => 1800])
                ->push(['access_token' => 'new', 'expires_in' => 1800]),
            self::SPDP.'/v1.beta/invoice_events*' => function (Request $request) {
                return $request->hasHeader('Authorization', 'Bearer old')
                    ? Http::response(['message' => 'expired'], 401)
                    : Http::response(['data' => [], 'has_after' => false]);
            },
        ]);

        $client = app(SuperPdpClient::class);
        $client->events();
        $client->events();

        $this->assertCount(2, $this->sentRequests('POST', '/oauth2/token'));
        $this->assertCount(3, $this->sentRequests('GET', '/invoice_events'));
    }

    public function test_writes_are_refused_when_credentials_belong_to_another_environment(): void
    {
        config(['einvoicing.superpdp.environment' => 'production']);
        app()->forgetInstance(SuperPdpClient::class);
        $this->fakeHttp();

        try {
            app(SuperPdpClient::class)->sendInvoice('%PDF', 'in-invoice-1', 'B2B');
            $this->fail('Le dépôt aurait dû être refusé.');
        } catch (SuperPdpException $e) {
            $this->assertStringContainsString('sandbox', $e->getMessage());
        }

        $this->assertCount(0, $this->sentRequests('POST', '/v1.beta/invoices?'));
    }

    public function test_read_requests_are_retried_on_server_errors(): void
    {
        Http::fake([
            self::SPDP.'/oauth2/token' => Http::response(['access_token' => 'at', 'expires_in' => 1800]),
            self::SPDP.'/v1.beta/invoice_events*' => Http::sequence()
                ->push(['message' => 'unavailable'], 503)
                ->push(['data' => [['id' => 1]], 'has_after' => false]),
        ]);

        $this->assertSame(1, app(SuperPdpClient::class)->events()['data'][0]['id']);
    }

    public function test_invoice_upload_is_never_retried_blindly(): void
    {
        $this->fakeHttp([self::SPDP.'/v1.beta/invoices?*' => Http::response(['message' => 'boom'], 502)]);

        $this->expectException(SuperPdpException::class);

        try {
            app(SuperPdpClient::class)->sendInvoice('%PDF', 'in-invoice-1', 'B2B');
        } finally {
            $this->assertCount(1, $this->sentRequests('POST', '/v1.beta/invoices?'));
        }
    }

    public function test_client_errors_are_permanent_but_not_auth_or_rate_limits(): void
    {
        $this->assertTrue((new SuperPdpException('x', 400))->isPermanent());
        $this->assertTrue((new SuperPdpException('x', 422))->isPermanent());
        $this->assertFalse((new SuperPdpException('x', 401))->isPermanent());
        $this->assertFalse((new SuperPdpException('x', 429))->isPermanent());
        $this->assertFalse((new SuperPdpException('x', 503))->isPermanent());
    }
}
