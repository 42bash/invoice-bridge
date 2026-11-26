<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures;
use Tests\TestCase;

class RecetteGuardTest extends TestCase
{
    use Fixtures;

    private function fakeCompany(string $name): void
    {
        Http::preventStrayRequests();
        Http::fake([self::IN.'/companies*' => Http::response(['data' => [['id' => 'c1', 'settings' => ['name' => $name], 'custom_fields' => []]]])]);
    }

    public function test_refuses_outside_the_sandbox(): void
    {
        $this->configureConnector(['einvoicing.superpdp.environment' => 'production', 'einvoicing.test_instance' => true]);
        $this->fakeCompany('Burger Queen (TEST)');

        $this->artisan('einvoicing:recette')->expectsOutputToContain('sandbox')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_refuses_without_the_test_instance_flag(): void
    {
        $this->configureConnector(['einvoicing.test_instance' => false]);
        $this->fakeCompany('Burger Queen (TEST)');

        $this->artisan('einvoicing:recette')->expectsOutputToContain('EINVOICING_TEST_INSTANCE')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_refuses_a_company_that_does_not_look_like_a_test_instance(): void
    {
        $this->configureConnector(['einvoicing.test_instance' => true]);
        $this->fakeCompany('42BASH');

        $this->artisan('einvoicing:recette')->expectsOutputToContain('TEST')->assertFailed();
        Http::assertSentCount(1);
    }
}
