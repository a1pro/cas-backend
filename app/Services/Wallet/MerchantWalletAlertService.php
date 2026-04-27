<?php

namespace App\Services\Wallet;

use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Services\Notifications\MerchantNotificationService;

class MerchantWalletAlertService
{
    public function __construct(private readonly MerchantNotificationService $merchantNotificationService)
    {
    }

    public function statusForWallet(?MerchantWallet $wallet): array
    {
        if (! $wallet) {
            return [
                'balance' => '0.00',
                'threshold' => '0.00',
                'is_low_balance' => false,
                'shortfall' => '0.00',
                'last_alert_at' => null,
                'cooldown_hours' => (int) config('talktocas.wallet_alerts.cooldown_hours', 12),
                'cooldown_ends_at' => null,
                'can_send_alert_now' => false,
                'auto_top_up_enabled' => false,
                'auto_top_up_amount' => '0.00',
                'auto_top_up_status' => 'off',
                'auto_top_up_message' => 'Wallet record not found.',
            ];
        }

        $balance = (float) $wallet->balance;
        $threshold = (float) $wallet->low_balance_threshold;
        $isLowBalance = $balance < $threshold;
        $shortfall = $isLowBalance ? ($threshold - $balance) : 0.0;
        $cooldownHours = max(1, (int) config('talktocas.wallet_alerts.cooldown_hours', 12));
        $lastAlertAt = $wallet->last_alert_at;
        $cooldownEndsAt = $lastAlertAt ? $lastAlertAt->copy()->addHours($cooldownHours) : null;
        $canSendAlertNow = $isLowBalance && (! $cooldownEndsAt || $cooldownEndsAt->isPast());
        $autoTopUpEnabled = (bool) $wallet->auto_top_up_enabled;

        return [
            'balance' => number_format($balance, 2, '.', ''),
            'threshold' => number_format($threshold, 2, '.', ''),
            'is_low_balance' => $isLowBalance,
            'shortfall' => number_format($shortfall, 2, '.', ''),
            'last_alert_at' => optional($lastAlertAt)?->toIso8601String(),
            'cooldown_hours' => $cooldownHours,
            'cooldown_ends_at' => optional($cooldownEndsAt)?->toIso8601String(),
            'can_send_alert_now' => $canSendAlertNow,
            'auto_top_up_enabled' => $autoTopUpEnabled,
            'auto_top_up_amount' => number_format((float) $wallet->auto_top_up_amount, 2, '.', ''),
            'auto_top_up_status' => $autoTopUpEnabled ? 'stripe_foundation_ready' : 'off',
            'auto_top_up_message' => $autoTopUpEnabled
                ? 'Auto top-up preference is saved and Stripe checkout simulation is now available on localhost.'
                : 'Auto top-up is currently off.',
        ];
    }

    public function notifyIfLowBalance(Merchant $merchant, string $reason = 'wallet_balance_below_threshold'): array
    {
        $wallet = $merchant->wallet;
        $status = $this->statusForWallet($wallet);

        if (! config('talktocas.wallet_alerts.enabled', true)) {
            return [
                'sent' => false,
                'reason' => 'alerts_disabled',
                'channels' => [],
                'status' => $status,
            ];
        }

        if (! $status['is_low_balance']) {
            return [
                'sent' => false,
                'reason' => 'wallet_healthy',
                'channels' => [],
                'status' => $status,
            ];
        }

        if (! $status['can_send_alert_now']) {
            return [
                'sent' => false,
                'reason' => 'cooldown_active',
                'channels' => [],
                'status' => $status,
            ];
        }

        $delivery = $this->merchantNotificationService->sendLowBalanceAlert($merchant, [
            'wallet' => $status,
            'reason' => $reason,
            'is_test' => false,
        ]);

        if (empty($delivery['channels'])) {
            return [
                'sent' => false,
                'reason' => 'no_delivery_channels',
                'channels' => [],
                'status' => $status,
            ];
        }

        $wallet?->update(['last_alert_at' => now()]);

        return [
            'sent' => true,
            'reason' => $reason,
            'channels' => $delivery['channels'],
            'status' => $this->statusForWallet($wallet?->fresh()),
        ];
    }

    public function sendTestAlert(Merchant $merchant): array
    {
        $status = $this->statusForWallet($merchant->wallet);

        $delivery = $this->merchantNotificationService->sendLowBalanceAlert($merchant, [
            'wallet' => $status,
            'reason' => 'manual_test',
            'is_test' => true,
        ]);

        return [
            'sent' => ! empty($delivery['channels']),
            'reason' => ! empty($delivery['channels']) ? 'manual_test' : 'no_delivery_channels',
            'channels' => $delivery['channels'] ?? [],
            'status' => $status,
        ];
    }
}
