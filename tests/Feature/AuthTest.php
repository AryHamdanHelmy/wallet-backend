<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'name' => 'Ary Hamdan',
        'username' => 'aryhamdan',
        'email' => 'ary@gmail.com',
        'phone' => '081234567890',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    public function test_register_berhasil_dan_mengembalikan_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['user' => ['id', 'name', 'username', 'email'], 'token'],
            ]);

        $this->assertDatabaseHas('users', ['username' => 'aryhamdan']);
        $this->assertDatabaseHas('wallets', ['balance' => 0]);
    }

    public function test_register_ditolak_jika_email_tidak_valid(): void
    {
        $response = $this->postJson('/api/auth/register', [
            ...$this->validPayload,
            'email' => 'user@',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_register_ditolak_jika_password_kurang_dari_delapan_karakter(): void
    {
        $response = $this->postJson('/api/auth/register', [
            ...$this->validPayload,
            'password' => 'pass12',
            'password_confirmation' => 'pass12',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_ditolak_jika_username_sudah_digunakan(): void
    {
        User::factory()->create(['username' => 'aryhamdan']);

        $response = $this->postJson('/api/auth/register', $this->validPayload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('username')
            ->assertJsonFragment(['username' => ['Username sudah digunakan.']]);
    }

    public function test_login_berhasil_dengan_kredensial_benar(): void
    {
        User::factory()->create([
            'email' => 'ary@gmail.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ary@gmail.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_gagal_mengembalikan_401(): void
    {
        User::factory()->create(['email' => 'ary@gmail.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ary@gmail.com',
            'password' => 'passwordsalah',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_pesan_login_gagal_tidak_membocorkan_email_terdaftar(): void
    {
        User::factory()->create(['email' => 'ary@gmail.com']);

        $emailAda = $this->postJson('/api/auth/login', [
            'email' => 'ary@gmail.com',
            'password' => 'salah',
        ]);

        $emailTidakAda = $this->postJson('/api/auth/login', [
            'email' => 'tidakada@gmail.com',
            'password' => 'salah',
        ]);

        $this->assertSame(
            $emailAda->json('message'),
            $emailTidakAda->json('message'),
            'Pesan error harus identik agar email terdaftar tidak bisa ditebak.'
        );
    }
}