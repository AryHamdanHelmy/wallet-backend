<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'email' => [
                'nullable', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama tidak boleh kosong.',
            'name.min' => 'Nama minimal 2 karakter.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
        ];
    }
}