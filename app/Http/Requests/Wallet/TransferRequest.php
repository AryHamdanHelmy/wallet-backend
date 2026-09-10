<?php

namespace App\Http\Requests\Wallet;

use App\Models\User;
use App\Services\PinAuthService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Penerima boleh diisi: Koku ID (dengan/tanpa "@"), nomor HP format apa pun,
 * atau email (untuk user yang mengisi email).
 */
class TransferRequest extends FormRequest
{
    private bool $recipientResolved = false;
    private ?User $recipient = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:100'],
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
            'amount.min' => 'Nominal minimal Rp' . number_format(config('wallet.min_transaction'), 0, ',', '.') . '.',
            'amount.max' => 'Nominal melebihi batas maksimum transaksi.',
            'idempotency_key.unique' => 'Transaksi ini sudah diproses sebelumnya.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('recipient') || ! $this->input('recipient')) {
                return;
            }

            $user = $this->resolveRecipient();

            if (! $user) {
                $validator->errors()->add('recipient', 'Penerima tidak ditemukan. Cek lagi Koku ID atau nomor HP-nya.');
                return;
            }

            if ($user->id === $this->user()->id) {
                $validator->errors()->add('recipient', 'Tidak bisa transfer ke diri sendiri.');
            }
        });
    }

    /** Dipanggil controller setelah validasi lolos. */
    public function recipientUser(): User
    {
        return $this->resolveRecipient() ?? abort(404);
    }

    /** Hasil pencarian di-cache, supaya validasi & controller cukup 1 query. */
    private function resolveRecipient(): ?User
    {
        if (! $this->recipientResolved) {
            $raw = trim((string) $this->input('recipient'));

            $this->recipient = filter_var($raw, FILTER_VALIDATE_EMAIL)
                ? User::where('email', mb_strtolower($raw))->first()
                : app(PinAuthService::class)->resolveUser($raw);

            $this->recipientResolved = true;
        }

        return $this->recipient;
    }
}