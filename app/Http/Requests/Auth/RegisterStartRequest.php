<?php

namespace App\Http\Requests\Auth;

use App\Rules\StrongPin;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Langkah 1 registrasi: Data Pribadi.
 */
class RegisterStartRequest extends FormRequest
{
    /** Koku ID yang tidak boleh dipakai user (rawan disalahgunakan untuk penipuan). */
    public const RESERVED_USERNAMES = [
        'admin', 'administrator', 'koku', 'kokuid', 'koku_id', 'support', 'help',
        'cs', 'customerservice', 'official', 'system', 'root', 'security',
        'bank', 'bi', 'ojk', 'info', 'noreply', 'api', 'www',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi SEBELUM validasi, supaya unique:users,phone membandingkan
     * format kanonik "628…" dengan isi database.
     */
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        $this->merge([
            'name' => is_string($this->input('name')) ? preg_replace('/\s+/', ' ', trim($this->input('name'))) : $this->input('name'),
            'phone' => PhoneNumber::normalize(is_string($phone) ? $phone : null) ?? $phone,
            'username' => is_string($this->input('username'))
                ? mb_strtolower(ltrim(trim($this->input('username')), '@'))
                : $this->input('username'),
        ]);
    }

    /**
     * Dipakai juga oleh endpoint cek ketersediaan Koku ID, supaya
     * "Tersedia" di UI dijamin lolos saat submit.
     */
    public static function usernameRules(): array
    {
        return [
            'bail',
            'required',
            'string',
            'regex:/^[a-z][a-z0-9_]{2,19}$/',
            function (string $attribute, mixed $value, Closure $fail) {
                if (in_array($value, self::RESERVED_USERNAMES, true)) {
                    $fail('Koku ID ini tidak bisa dipakai.');
                }
            },
            'unique:users,username',
        ];
    }

    public static function usernameMessages(): array
    {
        return [
            'username.required' => 'Koku ID tidak boleh kosong.',
            'username.regex' => 'Koku ID 3–20 karakter, diawali huruf, hanya huruf kecil, angka, dan _.',
            'username.unique' => 'Koku ID sudah dipakai. Coba yang lain.',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100', "regex:/^[\pL\s.'-]+$/u"],
            'phone' => [
                'bail',
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! preg_match('/^628\d{8,12}$/', (string) $value)) {
                        $fail('Nomor HP tidak valid. Contoh: 0812 3456 7890.');
                    }
                },
                'unique:users,phone',
            ],
            'username' => self::usernameRules(),
            'pin' => ['bail', 'required', 'digits:6', new StrongPin($this->input('phone'))],
            'terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama lengkap tidak boleh kosong.',
            'name.min' => 'Nama minimal 3 huruf.',
            'name.regex' => 'Nama hanya boleh berisi huruf, spasi, titik, dan tanda petik.',
            'phone.required' => 'Nomor HP tidak boleh kosong.',
            'phone.unique' => 'Nomor HP ini sudah terdaftar. Silakan masuk.',
            'pin.required' => 'PIN tidak boleh kosong.',
            'pin.digits' => 'PIN harus 6 digit angka.',
            'terms.accepted' => 'Setujui Syarat & Ketentuan untuk melanjutkan.',
            ...self::usernameMessages(),
        ];
    }
}