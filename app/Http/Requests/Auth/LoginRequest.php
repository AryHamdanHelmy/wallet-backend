<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Masuk dengan Nomor HP / Koku ID + PIN.
 *
 * remember = "Ingat perangkat ini": token berumur panjang (lihat AuthController).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'digits:6'],
            'remember' => ['sometimes', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.required' => 'Masukkan nomor HP atau Koku ID.',
            'pin.required' => 'Masukkan PIN kamu.',
            'pin.digits' => 'PIN harus 6 digit angka.',
        ];
    }
}