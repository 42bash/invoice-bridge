<?php

namespace Tests\Feature;

use App\Services\SandboxPeer;
use App\Services\SuperPdp\SuperPdpClient;
use App\Services\SuperPdp\SuperPdpException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;
use Tests\TestCase;

class SandboxPeerTest extends TestCase
{
    use Fixtures;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureConnector(['einvoicing.superpdp.peer_client_id' => 'peer-test', 'einvoicing.superpdp.peer_client_secret' => 'peer-secret']);
    }

    private function fakeSandbox(string $peerEnv = 'sandbox', int $peerCompany = 2): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($peerEnv, $peerCompany) {
            $peer = $request->hasHeader('Authorization', 'Bearer at-peer');
            $url = $request->url();

            if (str_ends_with($url, '/oauth2/token')) {
                return Http::response(['access_token' => $request['client_id'] === 'peer-test' ? 'at-peer' : 'at-me', 'expires_in' => 1800]);
            }

            if (str_contains($url, '/companies/me')) {
                return Http::response($peer
                    ? ['id' => $peerCompany, 'formal_name' => 'Tricatel', 'number' => '222222222', 'env' => $peerEnv]
                    : ['id' => 1, 'formal_name' => 'Burger Queen', 'number' => '111111111', 'env' => 'sandbox']);
            }

            if (str_contains($url, '/directory_entries')) {
                return Http::response(['data' => [['directory' => 'ppf', 'identifier' => '111111111_BQ', 'status' => 'created']]]);
            }

            if (str_contains($url, '/generate_test_invoice')) {
                return Http::response(['number' => 'X', 'type_code' => 380, 'issue_date' => '2025-01-01', 'buyer' => ['name' => 'Autre', 'legal_registration_identifier' => ['scheme' => '0002', 'value' => '999999999'], 'postal_address' => ['country_code' => 'FR']]]);
            }

            if (str_contains($url, '/invoices/convert')) {
                $this->sent['converted'] = json_decode($request->body(), true);

                return Http::response('<cii/>', 200, ['Content-Type' => 'application/xml']);
            }

            if (str_contains($url, '/validation_reports')) {
                return Http::response(['data' => [['is_valid' => true]]]);
            }

            if ($request->method() === 'POST' && str_contains($url, '/v1.beta/invoices?')) {
                $this->sent['upload'] = ['peer' => $peer, 'url' => $url, 'type' => $request->header('Content-Type')[0] ?? ''];

                return Http::response(['id' => 501]);
            }

            if ($request->method() === 'POST' && str_contains($url, '/invoice_events')) {
                $this->sent['event'] = ['peer' => $peer, 'body' => $request->data()];

                return Http::response(['id' => 9]);
            }

            return Http::response(['message' => 'inattendu'], 500);
        });
    }

    public function test_peer_sends_an_invoice_addressed_to_our_company(): void
    {
        $this->fakeSandbox();

        $result = app(SandboxPeer::class)->sendToMe('TRI-1');

        $this->assertSame(501, $result['superpdp_id']);
        $this->assertSame('111111111', $this->sent['converted']['buyer']['legal_registration_identifier']['value']);
        $this->assertSame(['scheme' => '0225', 'value' => '111111111_BQ'], $this->sent['converted']['buyer']['electronic_address']);
        $this->assertSame('TRI-1', $this->sent['converted']['number']);
        $this->assertTrue($this->sent['upload']['peer'], 'L\'envoi se fait avec les identifiants du partenaire.');
        $this->assertStringContainsString('external_id=peer-TRI-1', $this->sent['upload']['url']);
        $this->assertSame('application/xml', $this->sent['upload']['type']);
    }

    public function test_peer_answers_with_a_lifecycle_status(): void
    {
        $this->fakeSandbox();

        app(SandboxPeer::class)->respond(77, 'fr:210', 'TX_TVA_ERR', 'Taux erroné');

        $this->assertTrue($this->sent['event']['peer']);
        $this->assertSame(['invoice_id' => 77, 'status_code' => 'fr:210', 'details' => [['reason' => 'TX_TVA_ERR', 'notes' => [['subject' => 'AAI', 'contents' => [['content' => 'Taux erroné']]]]]]], $this->sent['event']['body']);
    }

    public function test_refuses_anything_outside_the_sandbox(): void
    {
        config(['einvoicing.superpdp.environment' => 'production']);
        app()->forgetInstance(SuperPdpClient::class);
        $this->fakeSandbox();

        $this->expectException(SuperPdpException::class);
        app(SandboxPeer::class)->sendToMe();
    }

    public function test_refuses_production_peer_credentials(): void
    {
        $this->fakeSandbox('production');

        $this->expectException(SuperPdpException::class);
        app(SandboxPeer::class)->sendToMe();
    }

    public function test_refuses_when_both_credentials_are_the_same_company(): void
    {
        $this->fakeSandbox('sandbox', 1);

        $this->expectExceptionMessage('même entreprise');
        app(SandboxPeer::class)->guard();
    }
}
