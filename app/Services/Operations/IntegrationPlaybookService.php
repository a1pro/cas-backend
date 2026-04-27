<?php

namespace App\Services\Operations;

use App\Models\BnplVoucherOrder;
use App\Models\StripeTopUpIntent;
use App\Models\Voucher;

class IntegrationPlaybookService
{
    public function payload(): array
    {
        $stripeUrl = rtrim((string) config('talktocas.stripe_finance.webhooks.endpoint_url'), '/');
        $providerUrl = rtrim((string) config('talktocas.provider_webhooks.endpoint_url'), '/');
        $whatsAppUrl = rtrim((string) config('talktocas.whatsapp.webhook_url'), '/');

        $latestTopUp = StripeTopUpIntent::query()->latest('id')->first();
        $latestBnpl = BnplVoucherOrder::query()->latest('id')->first();
        $latestVoucher = Voucher::query()->latest('id')->first();

        $stripeSample = [
            'id' => 'evt_talktocas_checkout_completed',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_talktocas',
                    'payment_status' => 'paid',
                    'metadata' => [
                        'checkout_code' => $latestTopUp?->checkout_code ?: 'TOPUP-DEMO-001',
                        'source' => 'merchant_wallet_top_up',
                    ],
                ],
            ],
        ];

        $providerSample = [
            'event_type' => $latestVoucher?->journey_type === 'food' ? 'order_completed' : 'ride_completed',
            'voucher_code' => $latestVoucher?->code ?: 'VOUCHER-DEMO-001',
            'provider_reference' => $latestVoucher?->external_reference ?: 'UB-DEMO-REF-001',
            'destination_match' => $latestVoucher?->journey_type === 'food' ? null : true,
            'notes' => 'Production provider callback example for settlement confirmation.',
        ];

        $bnplSample = [
            'order_code' => $latestBnpl?->checkout_code ?: 'BNPL-DEMO-001',
            'payment_status' => 'paid',
            'provider' => 'stripe_bnpl',
        ];

        return [
            'urls' => [
                'stripe_webhook' => $stripeUrl ?: null,
                'provider_webhook' => $providerUrl ?: null,
                'whatsapp_webhook' => $whatsAppUrl ?: null,
            ],
            'examples' => [
                'stripe' => [
                    'title' => 'Merchant wallet top-up settlement',
                    'payload' => $stripeSample,
                    'curl' => $stripeUrl
                        ? sprintf(
                            "curl -X POST '%s' -H 'Content-Type: application/json' -d '%s'",
                            $stripeUrl,
                            json_encode($stripeSample, JSON_UNESCAPED_SLASHES)
                        )
                        : null,
                ],
                'provider' => [
                    'title' => 'Ride / order completion confirmation',
                    'payload' => $providerSample,
                    'curl' => $providerUrl
                        ? sprintf(
                            "curl -X POST '%s' -H 'Content-Type: application/json' -d '%s'",
                            $providerUrl,
                            json_encode($providerSample, JSON_UNESCAPED_SLASHES)
                        )
                        : null,
                ],
                'bnpl' => [
                    'title' => 'BNPL paid order reference',
                    'payload' => $bnplSample,
                    'notes' => 'BNPL orders continue to settle through the Stripe webhook checkout.session.completed event.',
                ],
            ],
            'records' => [
                'latest_top_up_checkout_code' => $latestTopUp?->checkout_code,
                'latest_bnpl_checkout_code' => $latestBnpl?->checkout_code,
                'latest_voucher_code' => $latestVoucher?->code,
            ],
            'signature_headers' => [
                'stripe' => 'Stripe-Signature',
                'provider' => 'X-TALKTOCAS-PROVIDER-SIGNATURE',
                'whatsapp' => 'X-Hub-Signature-256',
            ],
        ];
    }
}
