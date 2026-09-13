<?php

namespace App\Console\Commands;

use App\Enums\TopupStatus;
use App\Models\Topup;
use App\Services\Payment\PaymentGatewayException;
use App\Services\TopupService;
use Illuminate\Console\Command;

class ReconcileTopups extends Command
{
    protected $signature = 'topups:reconcile
                            {order? : Order ID tertentu. Kosongkan untuk menyapu semua yang pending.}
                            {--minutes=5 : Hanya proses yang dibuat lebih dari sekian menit lalu.}';

    protected $description = 'Tanya status pembayaran langsung ke gateway, untuk menambal webhook yang tidak sampai.';

    public function handle(TopupService $topups): int
    {
        $query = Topup::where('status', TopupStatus::Pending);

        if ($order = $this->argument('order')) {
            $query->where('order_id', $order);
        } else {
            // Beri jeda supaya tidak menyalip webhook yang memang sedang
            // dalam perjalanan untuk pembayaran yang baru saja terjadi.
            $query->where('created_at', '<=', now()->subMinutes((int) $this->option('minutes')));
        }

        $pending = $query->get();

        if ($pending->isEmpty()) {
            $this->info('Tidak ada top-up yang perlu diperiksa.');

            return self::SUCCESS;
        }

        $this->info("Memeriksa {$pending->count()} top-up...");

        foreach ($pending as $topup) {
            try {
                $sebelum = $topup->status;
                $topup = $topups->reconcile($topup);

                $this->line(sprintf(
                    '  %s  %s -> %s',
                    $topup->order_id,
                    $sebelum->value,
                    $topup->status->value,
                ));
            } catch (PaymentGatewayException $e) {
                $this->warn("  {$topup->order_id}  gagal: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}