<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** PIN default semua user factory. Lolos aturan StrongPin. */
    public const DEFAULT_PIN = '142857';

    protected static ?string $pinHash;

    public function definition(): array
    {
        return [
            'name' => fake('id_ID')->name(),
            'username' => fake()->unique()->regexify('[a-z]{6}[0-9]{3}'),
            'email' => null,
            'phone' => '628' . fake()->unique()->numerify('#########'),
            'phone_verified_at' => now(),
            'pin' => static::$pinHash ??= Hash::make(self::DEFAULT_PIN),
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /** User dengan PIN tertentu, mis. ->withPin('280619'). */
    public function withPin(string $pin): static
    {
        return $this->state(fn () => ['pin' => Hash::make($pin)]);
    }

    public function withEmail(?string $email = null): static
    {
        return $this->state(fn () => ['email' => $email ?? fake()->unique()->safeEmail()]);
    }

    /** Akun sedang terkunci karena salah PIN. */
    public function locked(int $minutes = 15): static
    {
        return $this->state(fn () => ['pin_locked_until' => now()->addMinutes($minutes)]);
    }
}