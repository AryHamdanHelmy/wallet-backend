<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string'],
            'amount' => [
                'bail',
                'required',
                'integer',
                'min:' . config('wallet.min_transaction'),
                'max:' . config('wallet.max_transaction'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid', 'unique:transactions,idempotency_key'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient.required' => 'Penerima tidak boleh kosong.',
            'amount.required' => 'Nominal tidak boleh kosong.',
            'amount.integer' => 'Nominal harus berupa angka.',
            'amount.max' => 'Nominal melebihi batas maksimum transaksi.',
            'idempotency_key.unique' => 'Transaksi ini sudah diproses sebelumnya.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $recipient = $this->input('recipient');

            if (! $recipient) {
                return;
            }

            $user = \App\Models\User::where('email', $recipient)
                ->orWhere('phone', $recipient)
                ->first();

            if (! $user) {
                $validator->errors()->add('recipient', 'Penerima tidak ditemukan.');
                return;
            }

            if ($user->id === $this->user()->id) {
                $validator->errors()->add('recipient', 'Tidak bisa transfer ke diri sendiri.');
            }
        });
    }

    public function recipientUser(): \App\Models\User
    {
        return \App\Models\User::where('email', $this->recipient)
            ->orWhere('phone', $this->recipient)
            ->firstOrFail();
    }
}