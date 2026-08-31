<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class TopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => [
                'bail',
                'required',
                'integer',
                'min:' . config('wallet.min_transaction'),
                'max:' . config('wallet.max_transaction'),
            ],
            'idempotency_key' => ['nullable', 'uuid', 'unique:transactions,idempotency_key'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Nominal tidak boleh kosong.',
            'amount.integer' => 'Nominal harus berupa angka.',
            'amount.min' => 'Nominal minimal Rp' . number_format(config('wallet.min_transaction'), 0, ',', '.') . '.',
            'amount.max' => 'Nominal melebihi batas maksimum transaksi.',
            'idempotency_key.unique' => 'Transaksi ini sudah diproses sebelumnya.',
        ];
    }
}