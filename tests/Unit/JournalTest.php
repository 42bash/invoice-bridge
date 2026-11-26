<?php

namespace Tests\Unit;

use App\Support\Journal;
use PHPUnit\Framework\TestCase;

class JournalTest extends TestCase
{
    public function test_secrets_and_bank_details_are_masked(): void
    {
        $masked = Journal::mask([
            'client_secret' => 'abc',
            'nested' => ['access_token' => 'xyz', 'iban' => 'FR76...'],
            'message' => 'Authorization: Bearer eyJhbGciOi.secret.sig, compte FR76 3000 6000 0112 3456 7890 189, url ?token=123&x=1',
            'number' => 'FA-1',
        ]);

        $this->assertSame('***', $masked['client_secret']);
        $this->assertSame('***', $masked['nested']['access_token']);
        $this->assertSame('***', $masked['nested']['iban']);
        $this->assertStringNotContainsString('eyJhbGciOi', $masked['message']);
        $this->assertStringNotContainsString('3456 7890', $masked['message']);
        $this->assertStringNotContainsString('token=123', $masked['message']);
        $this->assertSame('FA-1', $masked['number']);
    }
}
