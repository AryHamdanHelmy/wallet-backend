<?php

namespace App\Support;

class TopupMethods
{
    /**
     * Daftar metode aktif beserta biaya adminnya, siap dikirim ke frontend.
     * Fee dihitung terhadap $amount kalau nominalnya sudah dipilih user,
     * supaya di layar pilih metode dia langsung lihat total yang dibayar.
     */
    public static function available(?int $amount = null): array
    {
        $methods = [];

        foreach (config('midtrans.methods') as $key => $method) {
            if (! ($method['enabled'] ?? false)) {
                continue;
            }

            $channels = [];

            foreach ($method['channels'] ?? [] as $channelKey => $channel) {
                if ($channel['enabled'] ?? false) {
                    $channels[] = ['key' => $channelKey, 'label' => $channel['label']];
                }
            }

            $methods[] = [
                'key'      => $key,
                'label'    => $method['label'],
                'channels' => $channels,
                'fee'      => $amount !== null ? self::fee($key, $amount) : null,
                'total'    => $amount !== null ? $amount + self::fee($key, $amount) : null,
            ];
        }

        return $methods;
    }

    /** Biaya admin dibulatkan ke atas ke rupiah penuh. */
    public static function fee(string $method, int $amount): int
    {
        $config = config("midtrans.methods.{$method}");

        if ($config === null) {
            return 0;
        }

        $flat    = (int) ($config['fee_flat'] ?? 0);
        $percent = (float) ($config['fee_percent'] ?? 0);

        return $flat + (int) ceil($amount * $percent / 100);
    }

    public static function isEnabled(string $method, ?string $channel = null): bool
    {
        $config = config("midtrans.methods.{$method}");

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        // Metode yang punya daftar channel (VA) wajib menyertakan channel.
        if (! empty($config['channels'])) {
            return $channel !== null
                && ($config['channels'][$channel]['enabled'] ?? false);
        }

        return $channel === null;
    }
}