<?php

namespace App\Services\Bnpl;

use App\Models\BnplVoucherOrder;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BnplCheckoutService
{
    public function options(): array
    {
        $plans = collect(config('talktocas.bnpl.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? Str::headline($key),
                    'amount_gbp' => number_format((float) ($plan['amount_gbp'] ?? 0), 2, '.', ''),
                    'description' => $plan['description'] ?? null,
                    'instalment_copy' => $plan['instalment_copy'] ?? null,
                ];
            })
            ->values()
            ->all();

        return [
            'enabled' => (bool) config('talktocas.bnpl.enabled', false),
            'provider' => (string) config('talktocas.bnpl.provider', 'stripe_bnpl'),
            'merchant_of_record' => (string) config('talktocas.bnpl.merchant_of_record', 'TALK to CAS'),
            'base_url' => rtrim((string) config('talktocas.bnpl.base_url', ''), '/'),
            'terms_summary' => (string) config('talktocas.bnpl.terms_summary', ''),
            'compliance_message' => (string) config('talktocas.bnpl.compliance_message', ''),
            'plans' => $plans,
            'simulate_payment_available' => (bool) config('talktocas.bnpl.allow_simulated_payment', false),
            'live_checkout_ready' => $this->liveCheckoutReady(),
            'checkout_mode' => $this->checkoutMode(),
            'checkout_success_url' => (string) config('talktocas.bnpl.success_url', ''),
            'checkout_cancel_url' => (string) config('talktocas.bnpl.cancel_url', ''),
        ];
    }

    public function createOrder(?User $user, array $payload): BnplVoucherOrder
    {
        $plan = $this->plan($payload['plan_key']);
        $paymentReference = 'pi_bnpl_pending_' . strtolower(Str::random(18));

        $order = BnplVoucherOrder::create([
            'user_id' => $user?->id,
            'checkout_code' => $this->uniqueCheckoutCode(),
            'plan_key' => $payload['plan_key'],
            'plan_name' => $plan['name'],
            'amount_gbp' => (float) $plan['amount_gbp'],
            'payment_provider' => (string) config('talktocas.bnpl.provider', 'stripe_bnpl'),
            'payment_status' => 'pending',
            'voucher_status' => 'pending_payment',
            'customer_name' => trim((string) $payload['customer_name']),
            'customer_email' => strtolower(trim((string) $payload['customer_email'])),
            'customer_phone' => trim((string) $payload['customer_phone']),
            'metadata' => [
                'flow' => 'bnpl_voucher_upgrade',
                'source_context' => Arr::get($payload, 'source_context'),
                'payment_reference' => $paymentReference,
                'checkout_mode' => $this->checkoutMode(),
                'live_checkout_ready' => $this->liveCheckoutReady(),
                'notes' => $this->liveCheckoutReady()
                    ? 'Live-ready BNPL checkout handoff created. Stripe webhook confirmation will issue the upgrade voucher automatically.'
                    : 'Review-mode BNPL checkout created. Add TALKTOCAS_BNPL_LINK to open a hosted payment page directly from this order.',
            ],
        ]);

        $order->update([
            'metadata' => array_merge($order->metadata ?? [], [
                'checkout_url' => $this->checkoutUrl($order),
                'review_url' => $this->reviewUrl($order),
                'success_url' => $this->successUrl($order),
                'cancel_url' => $this->cancelUrl($order),
            ]),
        ]);

        return $order->fresh();
    }

    public function markPaymentConfirmed(BnplVoucherOrder $order, array $meta = []): BnplVoucherOrder
    {
        if ($order->payment_status === 'paid' && $order->voucher_status === 'issued') {
            return $order->fresh();
        }

        $existingMeta = $order->metadata ?? [];
        $confirmedAt = now();

        $order->update([
            'payment_status' => 'paid',
            'voucher_status' => 'issued',
            'voucher_code' => $order->voucher_code ?: $this->voucherCode(),
            'checkout_completed_at' => $order->checkout_completed_at ?: $confirmedAt,
            'payment_confirmed_at' => $confirmedAt,
            'voucher_issued_at' => $confirmedAt,
            'metadata' => array_merge($existingMeta, $meta, [
                'settlement_mode' => Arr::get($meta, 'settlement_mode', 'webhook_confirmed'),
                'payment_reference' => Arr::get($meta, 'payment_reference', Arr::get($existingMeta, 'payment_reference')),
                'confirmed_at' => $confirmedAt->toIso8601String(),
            ]),
        ]);

        return $order->fresh();
    }

    public function markPaymentFailed(BnplVoucherOrder $order, array $meta = []): BnplVoucherOrder
    {
        if ($order->payment_status === 'paid') {
            return $order->fresh();
        }

        $existingMeta = $order->metadata ?? [];
        $failedAt = now();
        $reason = (string) Arr::get($meta, 'failure_reason', 'Payment was not completed.');

        $order->update([
            'payment_status' => 'failed',
            'voucher_status' => 'payment_failed',
            'metadata' => array_merge($existingMeta, $meta, [
                'failure_reason' => $reason,
                'failed_at' => $failedAt->toIso8601String(),
            ]),
        ]);

        return $order->fresh();
    }

    public function payload(BnplVoucherOrder $order): array
    {
        $metadata = $order->metadata ?? [];
        $checkoutUrl = (string) ($metadata['checkout_url'] ?? $this->checkoutUrl($order));
        $reviewUrl = (string) ($metadata['review_url'] ?? $this->reviewUrl($order));

        return [
            'id' => $order->id,
            'checkout_code' => $order->checkout_code,
            'plan_key' => $order->plan_key,
            'plan_name' => $order->plan_name,
            'amount' => number_format((float) $order->amount_gbp, 2, '.', ''),
            'amount_gbp' => number_format((float) $order->amount_gbp, 2, '.', ''),
            'payment_provider' => $order->payment_provider,
            'payment_status' => $order->payment_status,
            'voucher_status' => $order->voucher_status,
            'voucher_code' => $order->voucher_code,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'checkout_completed_at' => optional($order->checkout_completed_at)?->toIso8601String(),
            'payment_confirmed_at' => optional($order->payment_confirmed_at)?->toIso8601String(),
            'voucher_issued_at' => optional($order->voucher_issued_at)?->toIso8601String(),
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'updated_at' => optional($order->updated_at)?->toIso8601String(),
            'metadata' => $metadata,
            'voucher_download_ready' => $order->payment_status === 'paid' && filled($order->voucher_code),
            'simulate_payment_available' => (bool) config('talktocas.bnpl.allow_simulated_payment', false),
            'checkout_url' => $checkoutUrl,
            'review_url' => $reviewUrl,
            'success_url' => (string) ($metadata['success_url'] ?? $this->successUrl($order)),
            'cancel_url' => (string) ($metadata['cancel_url'] ?? $this->cancelUrl($order)),
            'live_checkout_ready' => (bool) ($metadata['live_checkout_ready'] ?? $this->liveCheckoutReady()),
            'checkout_mode' => (string) ($metadata['checkout_mode'] ?? $this->checkoutMode()),
            'payment_reference' => Arr::get($metadata, 'payment_reference'),
            'failure_reason' => Arr::get($metadata, 'failure_reason'),
        ];
    }

    public function payloadListForUser(User $user, int $limit = 5): array
    {
        return BnplVoucherOrder::query()
            ->where('user_id', $user->id)
            ->latest()
            ->take($limit)
            ->get()
            ->map(fn (BnplVoucherOrder $order) => $this->payload($order))
            ->all();
    }

    public function handleStripeWebhookPayload(array $payload): array
    {
        $eventType = (string) ($payload['type'] ?? $payload['event_type'] ?? 'unknown');
        $object = Arr::get($payload, 'data.object', []);

        if (! is_array($object)) {
            $object = [];
        }

        return match ($eventType) {
            'checkout.session.completed', 'payment_intent.succeeded', 'charge.succeeded' => $this->handleSuccessWebhook($object, $eventType),
            'checkout.session.async_payment_failed', 'checkout.session.expired', 'payment_intent.payment_failed', 'charge.failed' => $this->handleFailureWebhook($object, $eventType),
            default => [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'No BNPL handler is registered for this Stripe event.',
            ],
        };
    }

    private function handleSuccessWebhook(array $object, string $eventType): array
    {
        $order = $this->findOrderForWebhook($object);

        if (! $order) {
            return [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'No BNPL order matched this webhook payload.',
            ];
        }

        $order = $this->markPaymentConfirmed($order, [
            'settlement_mode' => 'stripe_webhook',
            'payment_event_type' => $eventType,
            'payment_reference' => $this->paymentReferenceFromObject($object),
            'stripe_object_id' => (string) ($object['id'] ?? ''),
        ]);

        return [
            'handled' => true,
            'event_type' => $eventType,
            'resource' => 'bnpl_voucher_order',
            'status' => $order->payment_status,
            'order' => $this->payload($order),
        ];
    }

    private function handleFailureWebhook(array $object, string $eventType): array
    {
        $order = $this->findOrderForWebhook($object);

        if (! $order) {
            return [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'No BNPL order matched this failed-payment webhook payload.',
            ];
        }

        $failureReason = (string) Arr::get($object, 'last_payment_error.message', Arr::get($object, 'failure_message', 'Payment failed at Stripe.'));
        $order = $this->markPaymentFailed($order, [
            'failure_reason' => $failureReason,
            'payment_event_type' => $eventType,
            'payment_reference' => $this->paymentReferenceFromObject($object),
            'stripe_object_id' => (string) ($object['id'] ?? ''),
        ]);

        return [
            'handled' => true,
            'event_type' => $eventType,
            'resource' => 'bnpl_voucher_order',
            'status' => $order->payment_status,
            'order' => $this->payload($order),
        ];
    }

    private function findOrderForWebhook(array $object): ?BnplVoucherOrder
    {
        $checkoutCode = $this->webhookCheckoutCode($object);
        if ($checkoutCode) {
            $order = BnplVoucherOrder::query()->where('checkout_code', $checkoutCode)->first();
            if ($order) {
                return $order;
            }
        }

        $paymentReference = $this->paymentReferenceFromObject($object);
        if (! filled($paymentReference)) {
            return null;
        }

        return BnplVoucherOrder::query()
            ->where('metadata->payment_reference', $paymentReference)
            ->first();
    }

    private function webhookCheckoutCode(array $object): ?string
    {
        $checkoutCode = strtoupper((string) Arr::get($object, 'metadata.checkout_code', Arr::get($object, 'checkout_code', Arr::get($object, 'client_reference_id', ''))));

        if (! str_starts_with($checkoutCode, 'BNPL-')) {
            return null;
        }

        return $checkoutCode;
    }

    private function paymentReferenceFromObject(array $object): ?string
    {
        $reference = (string) ($object['payment_intent'] ?? $object['id'] ?? Arr::get($object, 'metadata.payment_reference', ''));

        return filled($reference) ? $reference : null;
    }

    private function plan(string $planKey): array
    {
        $plan = config("talktocas.bnpl.plans.{$planKey}");

        if (! is_array($plan)) {
            throw new InvalidArgumentException('Selected voucher upgrade option is not available.');
        }

        return $plan;
    }

    private function uniqueCheckoutCode(): string
    {
        do {
            $code = 'BNPL-' . strtoupper(Str::random(10));
        } while (BnplVoucherOrder::where('checkout_code', $code)->exists());

        return $code;
    }

    private function voucherCode(): string
    {
        return 'TTC-BNPL-' . strtoupper(Str::random(8));
    }

    private function liveCheckoutReady(): bool
    {
        return filled((string) config('talktocas.bnpl.link', ''));
    }

    private function checkoutMode(): string
    {
        return $this->liveCheckoutReady() ? 'external_link' : 'review_only';
    }

    private function reviewUrl(BnplVoucherOrder $order): string
    {
        return rtrim((string) config('talktocas.bnpl.base_url', ''), '/') . '/' . $order->checkout_code;
    }

    private function checkoutUrl(BnplVoucherOrder $order): string
    {
        $link = trim((string) config('talktocas.bnpl.link', ''));
        if ($link === '') {
            return $this->reviewUrl($order);
        }

        $replaced = strtr($link, [
            '{CHECKOUT_CODE}' => (string) $order->checkout_code,
            '{CUSTOMER_EMAIL}' => urlencode((string) $order->customer_email),
            '{PLAN_KEY}' => urlencode((string) $order->plan_key),
            '{SUCCESS_URL}' => urlencode($this->successUrl($order)),
            '{CANCEL_URL}' => urlencode($this->cancelUrl($order)),
        ]);

        if ($replaced !== $link) {
            return $replaced;
        }

        $separator = str_contains($link, '?') ? '&' : '?';

        return $link . $separator . http_build_query([
            'checkout_code' => $order->checkout_code,
            'email' => $order->customer_email,
            'plan' => $order->plan_key,
            'success_url' => $this->successUrl($order),
            'cancel_url' => $this->cancelUrl($order),
        ]);
    }

    private function successUrl(BnplVoucherOrder $order): string
    {
        $template = (string) config('talktocas.bnpl.success_url', $this->reviewUrl($order) . '?status=success');

        return strtr($template, [
            '{CHECKOUT_CODE}' => (string) $order->checkout_code,
        ]);
    }

    private function cancelUrl(BnplVoucherOrder $order): string
    {
        $template = (string) config('talktocas.bnpl.cancel_url', $this->reviewUrl($order) . '?status=cancelled');

        return strtr($template, [
            '{CHECKOUT_CODE}' => (string) $order->checkout_code,
        ]);
    }
}
