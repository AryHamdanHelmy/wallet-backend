<?php

namespace App\Http\Requests\Wallet;

use App\Support\TopupMethods;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CreateTopupRequest extends FormRequest
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
                'min:' . config('midtrans.min_amount'),
                'max:' . config('midtrans.max_amount'),
            ],
            'method'  => ['bail', 'required', 'string'],
            'channel' => ['nullable', 'string'],
        ];
    }

    /**
     * Kombinasi method + channel divalidasi setelah aturan dasar lolos,
     * supaya pesan errornya tidak menumpuk saat method-nya saja sudah salah.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! TopupMethods::isEnabled($this->string('method'), $this->input('channel'))) {
                    $validator->errors()->add(
                        'method',
                        'Metode pembayaran tidak tersedia.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Nominal tidak boleh kosong.',
            'amount.integer'  => 'Nominal harus berupa angka.',
            'amount.min'      => 'Nominal minimal Rp' . number_format(config('midtrans.min_amount'), 0, ',', '.') . '.',
            'amount.max'      => 'Nominal maksimal Rp' . number_format(config('midtrans.max_amount'), 0, ',', '.') . '.',
            'method.required' => 'Pilih metode pembayaran.',
        ];
    }
}