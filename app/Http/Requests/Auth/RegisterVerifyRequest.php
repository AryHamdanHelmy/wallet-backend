<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Langkah 2 registrasi: verifikasi OTP.
 */
class RegisterVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registration_id' => ['required', 'uuid'],
            'otp' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'registration_id.required' => 'Sesi pendaftaran tidak ditemukan. Isi ulang data kamu.',
            'registration_id.uuid' => 'Sesi pendaftaran tidak valid. Isi ulang data kamu.',
            'otp.required' => 'Masukkan kode verifikasi.',
            'otp.digits' => 'Kode verifikasi harus 6 digit.',
        ];
    }
}