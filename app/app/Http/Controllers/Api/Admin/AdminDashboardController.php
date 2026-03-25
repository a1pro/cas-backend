<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Models\Voucher;
use App\Models\WalletTransaction;

class AdminDashboardController extends BaseController
{
    public function dashboard()
    {
        return $this->success([
            'stats' => [
                'total_users' => \App\Models\User::count(),
                'total_merchants' => Merchant::count(),
                'total_venues' => \App\Models\Venue::count(),
                'issued_vouchers' => Voucher::where('status', 'issued')->count(),
                'redeemed_vouchers' => Voucher::where('status', 'redeemed')->count(),
                'wallet_debits_total' => (float) WalletTransaction::where('type', 'debit')->sum('amount'),
            ],
            'low_balance_merchants' => Merchant::with('wallet')
                ->get()
                ->filter(fn ($merchant) => $merchant->wallet && $merchant->wallet->balance < $merchant->wallet->low_balance_threshold)
                ->values(),
            'recent_transactions' => WalletTransaction::with(['merchant', 'voucher'])
                ->latest()
                ->take(15)
                ->get(),
        ]);
    }

    public function merchants()
    {
        return $this->success(
            Merchant::with(['user', 'wallet', 'venues'])->orderBy('business_name')->get()
        );
    }
}
