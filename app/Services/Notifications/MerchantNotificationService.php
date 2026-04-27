<?php

namespace App\Services\Notifications;

use App\Mail\MerchantApprovedMail;
use App\Models\Merchant;
use App\Models\Voucher;
use App\Services\WhatsApp\ThreeSixtyDialogService;
use Illuminate\Support\Facades\Mail;

class MerchantNotificationService
{
    public function __construct(private readonly ThreeSixtyDialogService $threeSixtyDialogService)
    {
    }

    public function sendApprovalMail(Merchant $merchant): void
    {
        if (! $merchant->contact_email) {
            return;
        }

        Mail::to($merchant->contact_email)->queue(new MerchantApprovedMail($merchant));
    }

    public function notifyMerchantVoucherIssued(Voucher $voucher): void
    {
        $merchant = $voucher->merchant;

        if ($merchant?->contact_email) {
            Mail::raw(
                sprintf(
                    'New %s voucher %s was issued for %s at %s. Wallet will only be charged after verified completion.',
                    $voucher->journey_type,
                    $voucher->code,
                    $voucher->venue?->name ?? 'your venue',
                    $voucher->issued_at?->format('d M Y H:i')
                ),
                function ($message) use ($merchant) {
                    $message->to($merchant->contact_email)->subject('TALK to CAS voucher issued');
                }
            );
        }

        if ($merchant?->whatsapp_number) {
            $this->threeSixtyDialogService->sendText(
                $merchant->whatsapp_number,
                sprintf(
                    'New voucher %s was issued for %s. Wallet will be charged only after verified completion.',
                    $voucher->code,
                    $voucher->venue?->name ?? 'your venue'
                )
            );
        }
    }

    public function sendLowBalanceAlert(Merchant $merchant, array $context = []): array
    {
        $wallet = $context['wallet'] ?? [];
        $isTest = (bool) ($context['is_test'] ?? false);
        $channels = [];

        $subject = $isTest
            ? 'TALK to CAS wallet alert test'
            : 'TALK to CAS low wallet balance alert';

        $emailBody = trim(sprintf(
            "%s\n\nBusiness: %s\nCurrent balance: £%s\nThreshold: £%s\nShortfall: £%s\nAuto top-up: %s\n\n%s",
            $isTest
                ? 'This is a manual test alert from the merchant dashboard.'
                : 'Your merchant wallet has fallen below the configured threshold.',
            $merchant->business_name,
            $wallet['balance'] ?? '0.00',
            $wallet['threshold'] ?? '0.00',
            $wallet['shortfall'] ?? '0.00',
            ! empty($wallet['auto_top_up_enabled'])
                ? 'Enabled (preference saved, Stripe charge not live yet)'
                : 'Off',
            $isTest
                ? 'Use this to confirm the email and WhatsApp delivery channels are working.'
                : 'Please top up the wallet to avoid interrupted voucher delivery.'
        ));

        $whatsAppBody = trim(sprintf(
            "%s\n%s\nBalance £%s / Threshold £%s\nShortfall £%s\n%s",
            $isTest ? 'TALK to CAS test wallet alert.' : 'Low wallet balance alert.',
            $merchant->business_name,
            $wallet['balance'] ?? '0.00',
            $wallet['threshold'] ?? '0.00',
            $wallet['shortfall'] ?? '0.00',
            $isTest
                ? 'This is a delivery test from your dashboard.'
                : 'Top up soon to keep vouchers live.'
        ));

        if ($merchant->contact_email) {
            Mail::raw($emailBody, function ($message) use ($merchant, $subject) {
                $message->to($merchant->contact_email)->subject($subject);
            });

            $channels[] = 'email';
        }

        if ($merchant->whatsapp_number) {
            $this->threeSixtyDialogService->sendText($merchant->whatsapp_number, $whatsAppBody);
            $channels[] = 'whatsapp';
        }

        return [
            'channels' => $channels,
            'is_test' => $isTest,
        ];
    }
}
