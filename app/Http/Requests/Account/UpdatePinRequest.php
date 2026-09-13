<?php

namespace App\Http\Requests\Account;

use App\Rules\StrongPin;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePinRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_pin' => ['bail', 'required', 'digits:6'],
            'pin' => [
                'bail', 'required', 'digits:6', 'different:current_pin', 'confirmed',
                new StrongPin($this->user()->phone),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_pin.required' => 'Masukkan PIN kamu saat ini.',
            'pin.digits' => 'PIN harus 6 digit angka.',
            'pin.different' => 'PIN baru harus berbeda dari PIN lama.',
            'pin.confirmed' => 'Konfirmasi PIN tidak cocok.',
        ];
    }
}