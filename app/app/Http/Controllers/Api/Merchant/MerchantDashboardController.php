<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Models\Voucher;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MerchantDashboardController extends BaseController
{
    public function dashboard(Request $request)
    {
        $merchant = $this->merchantForUser($request);

        return $this->success([
            'merchant' => $merchant->load('wallet'),
            'stats' => [
                'active_venues' => $merchant->venues()->where('is_active', true)->count(),
                'issued_vouchers' => $merchant->vouchers()->where('status', 'issued')->count(),
                'redeemed_vouchers' => $merchant->vouchers()->where('status', 'redeemed')->count(),
                'wallet_balance' => $merchant->wallet->balance,
            ],
            'recent_vouchers' => Voucher::with(['venue', 'user'])
                ->where('merchant_id', $merchant->id)
                ->latest()
                ->take(10)
                ->get(),
            'recent_transactions' => WalletTransaction::where('merchant_id', $merchant->id)
                ->latest()
                ->take(10)
                ->get(),
        ]);
    }

    public function topUp(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        $wallet = $merchant->wallet;

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $before = (float) $wallet->balance;
        $after = $before + (float) $validated['amount'];

        $wallet->update(['balance' => $after]);

        WalletTransaction::create([
            'merchant_id' => $merchant->id,
            'merchant_wallet_id' => $wallet->id,
            'type' => 'deposit',
            'amount' => $validated['amount'],
            'balance_before' => $before,
            'balance_after' => $after,
            'reference' => 'TOPUP-' . strtoupper(Str::random(6)),
            'notes' => 'Manual top-up from merchant dashboard',
        ]);

        return $this->success($wallet->fresh(), 'Wallet topped up successfully');
    }

    public function redeemVoucher(Request $request, Voucher $voucher)
    {
        $merchant = $this->merchantForUser($request);
        $wallet = $merchant->wallet;

        if ((int) $voucher->merchant_id !== (int) $merchant->id) {
            return $this->error('Voucher does not belong to this merchant', 403);
        }

        if ($voucher->status === 'redeemed') {
            return $this->error('Voucher already redeemed', 422);
        }

        $charge = (float) $voucher->total_charge;
        $before = (float) $wallet->balance;

        if ($before < $charge) {
            return $this->error('Insufficient wallet balance', 422);
        }

        $after = $before - $charge;

        $wallet->update([
            'balance' => $after,
            'last_alert_at' => $after < (float) $wallet->low_balance_threshold ? now() : $wallet->last_alert_at,
        ]);

        $voucher->update([
            'status' => 'redeemed',
            'redeemed_at' => now(),
            'external_reference' => 'UBER-' . strtoupper(Str::random(10)),
        ]);

        WalletTransaction::create([
            'merchant_id' => $merchant->id,
            'merchant_wallet_id' => $wallet->id,
            'voucher_id' => $voucher->id,
            'type' => 'debit',
            'amount' => $charge,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference' => 'REDEEM-' . strtoupper(Str::random(6)),
            'notes' => 'Wallet charged after verified voucher redemption',
        ]);

        return $this->success([
            'wallet' => $wallet->fresh(),
            'voucher' => $voucher->fresh(['venue', 'user']),
            'low_balance_alert' => $after < (float) $wallet->low_balance_threshold,
        ], 'Voucher redeemed and wallet charged successfully');
    }

    private function merchantForUser(Request $request): Merchant
    {
        return Merchant::with(['wallet', 'venues'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
