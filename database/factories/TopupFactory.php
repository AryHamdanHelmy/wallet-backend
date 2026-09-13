<?php

namespace Database\Factories;

use App\Enums\TopupStatus;
use App\Models\Topup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Topup>
 */
class TopupFactory extends Factory
{
    protected $model = Topup::class;

    public function definition(): array
    {
        $amount = $this->faker->numberBetween(1, 20) * 10_000;
        $fee    = 4_000;

        return [
            'user_id'      => User::factory(),
            'wallet_id'    => fn (array $attr) => User::find($attr['user_id'])->wallet->id,
            'order_id'     => 'KOKU-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8)),
            'amount'       => $amount,
            'fee'          => $fee,
            'gross_amount' => $amount + $fee,
            'method'       => 'va',
            'channel'      => 'bca',
            'status'       => TopupStatus::Pending,
            'gateway_transaction_id' => (string) Str::uuid(),
            'payment_payload' => ['bank' => 'bca', 'va_number' => '12345678901'],
            'expires_at'   => now()->addHour(),
        ];
    }

    /** Top-up milik user yang sudah ada, tanpa bikin user baru. */
    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id'   => $user->id,
            'wallet_id' => $user->wallet->id,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status'  => TopupStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status'     => TopupStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }

    /** Masih pending tapi masa berlakunya sudah lewat (webhook expire telat). */
    public function stale(): static
    {
        return $this->state(fn () => [
            'status'     => TopupStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function qris(): static
    {
        return $this->state(fn () => [
            'method'  => 'qris',
            'channel' => null,
            'payment_payload' => ['qr_string' => '000201010212...'],
        ]);
    }
}