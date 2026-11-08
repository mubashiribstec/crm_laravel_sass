<?php

namespace Tests\Unit;

use App\Support\Xplogent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which numbers the CRM hands to Xplogent to dial (Muse's PR #2, reviewed 8 Nov):
 * UK numbers as before, international E.164 passed through, a short extension only when
 * it is one of your test extensions, never an emergency number.
 */
class XplogentToE164Test extends TestCase
{
    public static function numbers(): array
    {
        return [
            'UK mobile' => ['07700 900123', '+447700900123'],
            'UK landline' => ['0113 496 0000', '+441134960000'],
            'UK in +44 form' => ['+44 7700 900123', '+447700900123'],
            'UK +44 with the national 0 left in' => ['+44 07700 900123', '+447700900123'],
            'UK 0044' => ['0044 7700 900123', '+447700900123'],
            'Pakistan, E.164' => ['+92 320 1234567', '+923201234567'],
            'US, E.164' => ['+1 (212) 555-0100', '+12125550100'],
            'your test extension' => ['505', '505'],
            'your test feature code' => ['*43', '*43'],
            'a short number that is not yours (junk in a record)' => ['12345', null],
            'another extension' => ['204', null],
            'emergency 999' => ['999', null],
            'emergency 112' => ['112', null],
            'emergency 911' => ['911', null],
            'emergency 000' => ['000', null],
            'a lone +44' => ['+44', null],
            'too short' => ['7', null],
            'not a UK number, no country code' => ['0800123', null],
            'letters' => ['call me', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_to_e164(?string $input, ?string $expected): void
    {
        config(['xplogent.test_extensions' => ['505', '*43', '999']]);   // 999 listed: still refused
        $this->assertSame($expected, Xplogent::toE164($input));
    }
}
