<?php

namespace Tests\Unit;

use App\Rules\StrongPin;
use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Helper murni tanpa database: normalisasi nomor HP & aturan PIN.
 */
class AuthHelpersTest extends TestCase
{
    public static function phoneFormats(): array
    {
        return [
            'lokal 08' => ['081234567890', '6281234567890'],
            'tanpa 0' => ['81234567890', '6281234567890'],
            'kanonik' => ['6281234567890', '6281234567890'],
            'plus & strip' => ['+62 812-3456-7890', '6281234567890'],
            'kurung' => ['(0812) 3456 7890', '6281234567890'],
            'terlalu pendek' => ['0812345', null],
            'telepon rumah' => ['021555666', null],
            'bukan nomor' => ['hamdan', null],
        ];
    }

    #[DataProvider('phoneFormats')]
    public function test_normalisasi_nomor_hp(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_format_dan_mask_nomor_hp(): void
    {
        $this->assertSame('+62 812 3456 7890', PhoneNumber::format('6281234567890'));
        $this->assertSame('+62 812-****-7890', PhoneNumber::mask('6281234567890'));
    }

    public static function pins(): array
    {
        return [
            'acak' => ['142857', true],
            'acak 2' => ['280619', true],
            'sama semua' => ['111111', false],
            'naik' => ['123456', false],
            'turun' => ['987654', false],
            'pola 2 digit' => ['121212', false],
            'pola 3 digit' => ['123123', false],
            'potongan nomor HP' => ['567890', false],
        ];
    }

    #[DataProvider('pins')]
    public function test_aturan_pin_kuat(string $pin, bool $valid): void
    {
        $failed = false;
        (new StrongPin('6281234567890'))->validate('pin', $pin, function () use (&$failed) {
            $failed = true;
        });

        $this->assertSame($valid, ! $failed);
    }
}