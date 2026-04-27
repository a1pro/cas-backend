<?php

namespace App\Console\Commands;

use App\Services\Voucher\VoucherService;
use Illuminate\Console\Command;

class ExpireStaleVouchers extends Command
{
    protected $signature = 'cas:expire-vouchers';
    protected $description = 'Expire issued vouchers older than their 24 hour window';

    public function handle(VoucherService $voucherService): int
    {
        $count = $voucherService->expireStaleIssuedVouchers();
        $this->info("Expired {$count} stale voucher(s).");

        return self::SUCCESS;
    }
}
