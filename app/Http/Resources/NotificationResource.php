<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Membungkus DatabaseNotification jadi bentuk yang siap dirender bell.
 *
 * Kolom "data" isinya JSON bebas, jadi setiap pembacaan di sini memakai
 * null coalescing. Notifikasi yang tersimpan sebelum payload berubah
 * tetap bisa dirender (judulnya jadi fallback) alih-alih menjatuhkan
 * seluruh response dengan "undefined array key".
 *
 * @mixin \Illuminate\Notifications\DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'event' => $data['event'] ?? 'info',
            'title' => $data['title'] ?? 'Pemberitahuan',
            'body' => $data['body'] ?? null,

            'amount' => $data['amount'] ?? null,
            'amount_formatted' => $data['amount_formatted'] ?? null,
            'direction' => $data['direction'] ?? null,

            'transaction_id' => $data['transaction_id'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'counterparty' => $data['counterparty'] ?? null,

            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),

            // Dipakai buat label "2 jam lalu" tanpa frontend perlu
            // library tanggal. Bahasa mengikuti locale aplikasi.
            'created_at_human' => $this->created_at->diffForHumans(),
        ];
    }
}