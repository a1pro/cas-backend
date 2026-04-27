<?php

namespace App\Services\Voucher;

use App\Models\Merchant;
use App\Models\Voucher;
use App\Models\VoucherProviderEvent;
use App\Models\WalletTransaction;
use App\Services\Affiliate\AffiliateCommissionService;
use App\Services\Affiliate\AffiliateTrackingService;
use App\Services\Fraud\FraudPreventionService;
use App\Services\Wallet\MerchantWalletAlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VoucherProviderVerificationService
{
    public function __construct(
        private readonly AffiliateTrackingService $affiliateTrackingService,
        private readonly AffiliateCommissionService $affiliateCommissionService,
        private readonly FraudPreventionService $fraudPreventionService,
        private readonly MerchantWalletAlertService $walletAlertService,
    ) {
    }

    public function providerNameForVoucher(Voucher $voucher): string
    {
        return $voucher->journey_type === 'food' ? 'ubereats' : 'uber';
    }

    public function summaryForMerchant(Merchant $merchant): array
    {
        $pendingCount = Voucher::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', 'issued')
            ->count();

        $eventQuery = VoucherProviderEvent::query()->where('merchant_id', $merchant->id);

        return [
            'pending_count' => $pendingCount,
            'confirmed_count' => (clone $eventQuery)->where('verification_result', 'confirmed')->count(),
            'cancelled_count' => (clone $eventQuery)->where('verification_result', 'cancelled')->count(),
            'rejected_count' => (clone $eventQuery)->where('verification_result', 'rejected')->count(),
            'recent_issues_count' => (clone $eventQuery)
                ->whereIn('verification_result', ['cancelled', 'rejected'])
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ];
    }

    public function pendingVoucherPayloads(Merchant $merchant, int $limit = 5): array
    {
        return Voucher::query()
            ->with(['user', 'venue'])
            ->where('merchant_id', $merchant->id)
            ->where('status', 'issued')
            ->latest('issued_at')
            ->take($limit)
            ->get()
            ->map(fn (Voucher $voucher) => $this->voucherPayload($voucher))
            ->values()
            ->all();
    }

    public function recentEventPayloads(Merchant $merchant, int $limit = 10): array
    {
        return VoucherProviderEvent::query()
            ->with(['voucher.venue', 'voucher.user'])
            ->where('merchant_id', $merchant->id)
            ->latest('occurred_at')
            ->latest('id')
            ->take($limit)
            ->get()
            ->map(fn (VoucherProviderEvent $event) => $this->eventPayload($event))
            ->values()
            ->all();
    }

    public function latestVerificationPayloadForVoucher(Voucher $voucher): array
    {
        $voucher->loadMissing(['providerEvents' => fn ($query) => $query->latest('occurred_at')->latest('id')]);
        $event = $voucher->providerEvents->first();

        return [
            'provider_name' => $this->providerNameForVoucher($voucher),
            'status' => $event?->verification_result ?? ($voucher->status === 'redeemed' ? 'confirmed' : ($voucher->status === 'cancelled' ? 'cancelled' : 'pending')),
            'last_event_type' => $event?->event_type,
            'provider_reference' => $event?->provider_reference ?? $voucher->external_reference,
            'destination_match' => $event?->destination_match,
            'charge_applied' => (bool) ($event?->charge_applied ?? false),
            'amount_charged' => $event?->amount_charged !== null
                ? number_format((float) $event->amount_charged, 2, '.', '')
                : ($voucher->status === 'redeemed' ? number_format((float) $voucher->total_charge, 2, '.', '') : null),
            'notes' => $event?->notes,
            'occurred_at' => optional($event?->occurred_at)->toIso8601String(),
        ];
    }

    public function simulateEvent(Voucher $voucher, string $eventType, array $attributes = []): array
    {
        return match ($eventType) {
            'ride_completed', 'order_completed' => $this->confirmCompletion($voucher, $eventType, $attributes),
            'destination_mismatch' => $this->rejectMismatch($voucher, $eventType, $attributes),
            'order_cancelled', 'ride_terminated_early' => $this->cancelWithoutCharge($voucher, $eventType, $attributes),
            default => throw ValidationException::withMessages([
                'event_type' => ['Unsupported provider event type.'],
            ]),
        };
    }

    public function voucherPayload(Voucher $voucher): array
    {
        $voucher->loadMissing(['user', 'venue']);

        return [
            'id' => $voucher->id,
            'code' => $voucher->code,
            'status' => $voucher->status,
            'journey_type' => $voucher->journey_type,
            'provider_name' => $voucher->provider_name ?: $this->providerNameForVoucher($voucher),
            'offer_type' => $voucher->offer_type,
            'ride_trip_type' => $voucher->ride_trip_type,
            'voucher_link_url' => $voucher->voucher_link_url,
            'voucher_value' => number_format((float) $voucher->voucher_value, 2, '.', ''),
            'total_charge' => number_format((float) $voucher->total_charge, 2, '.', ''),
            'issued_at' => optional($voucher->issued_at)->toIso8601String(),
            'redeemed_at' => optional($voucher->redeemed_at)->toIso8601String(),
            'expires_at' => optional($voucher->expires_at)->toIso8601String(),
            'user' => $voucher->user ? [
                'id' => $voucher->user->id,
                'name' => $voucher->user->name,
                'email' => $voucher->user->email,
                'phone' => $voucher->user->phone,
            ] : null,
            'venue' => $voucher->venue ? [
                'id' => $voucher->venue->id,
                'name' => $voucher->venue->name,
                'postcode' => $voucher->venue->postcode,
            ] : null,
            'verification' => $this->latestVerificationPayloadForVoucher($voucher),
        ];
    }

    public function eventPayload(VoucherProviderEvent $event): array
    {
        $event->loadMissing(['voucher.venue', 'voucher.user']);

        return [
            'id' => $event->id,
            'voucher_id' => $event->voucher_id,
            'voucher_code' => $event->voucher?->code,
            'provider_name' => $event->provider_name,
            'event_type' => $event->event_type,
            'verification_result' => $event->verification_result,
            'provider_reference' => $event->provider_reference,
            'destination_match' => $event->destination_match,
            'charge_applied' => (bool) $event->charge_applied,
            'amount_charged' => $event->amount_charged !== null ? number_format((float) $event->amount_charged, 2, '.', '') : null,
            'notes' => $event->notes,
            'occurred_at' => optional($event->occurred_at)->toIso8601String(),
            'voucher' => $event->voucher ? [
                'id' => $event->voucher->id,
                'status' => $event->voucher->status,
                'journey_type' => $event->voucher->journey_type,
            ] : null,
            'user' => $event->voucher?->user ? [
                'id' => $event->voucher->user->id,
                'name' => $event->voucher->user->name,
                'phone' => $event->voucher->user->phone,
            ] : null,
            'venue' => $event->voucher?->venue ? [
                'id' => $event->voucher->venue->id,
                'name' => $event->voucher->venue->name,
                'postcode' => $event->voucher->venue->postcode,
            ] : null,
        ];
    }



    public function handleWebhookPayload(array $payload, array $headers = []): array
    {
        if (! config('talktocas.provider_webhooks.enabled', true)) {
            throw new RuntimeException('Provider webhooks are disabled in config.');
        }

        $verification = $this->verifyWebhookSignature($payload, $headers);

        if (($verification['allowed'] ?? false) !== true) {
            throw new RuntimeException($verification['reason'] ?? 'Invalid provider webhook signature.');
        }

        $voucher = $this->resolveVoucherFromPayload($payload);
        $eventType = $this->normaliseWebhookEventType((string) ($payload['event_type'] ?? $payload['type'] ?? ''));

        $attributes = [
            'provider_reference' => $payload['provider_reference'] ?? $payload['reference'] ?? null,
            'destination_match' => array_key_exists('destination_match', $payload) ? (bool) $payload['destination_match'] : null,
            'notes' => $payload['notes'] ?? $payload['message'] ?? null,
        ];

        $result = $this->simulateEvent($voucher, $eventType, $attributes);

        return [
            'accepted' => true,
            'verification' => $verification,
            'event_type' => $eventType,
            'voucher' => $result['voucher'],
            'provider_event' => $result['event'],
            'low_balance_alert' => $result['low_balance_alert'],
        ];
    }

    private function verifyWebhookSignature(array $payload, array $headers = []): array
    {
        $requireValid = (bool) config('talktocas.provider_webhooks.require_valid_signature', false)
            || (bool) config('talktocas.operations.live_mode', false);
        $allowUnsignedLocalhost = (bool) config('talktocas.provider_webhooks.allow_unsigned_localhost', true);
        $secret = (string) config('talktocas.provider_webhooks.shared_secret', '');

        $signature = trim((string) ($headers['x-talktocas-provider-signature'] ?? $headers['x-provider-signature'] ?? ''));
        $rawPayload = (string) ($headers['raw_payload'] ?? json_encode($payload));

        if ($signature === '') {
            if ($requireValid || ! $allowUnsignedLocalhost) {
                return [
                    'allowed' => false,
                    'reason' => 'Unsigned provider webhooks are not allowed in this environment.',
                ];
            }

            return [
                'allowed' => true,
                'verified' => false,
                'mode' => 'unsigned_localhost',
            ];
        }

        if ($secret === '') {
            return [
                'allowed' => false,
                'reason' => 'Provider webhook signature was sent but TALKTOCAS_PROVIDER_WEBHOOK_SHARED_SECRET is missing.',
            ];
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);

        if (! hash_equals($expected, $signature)) {
            return [
                'allowed' => false,
                'reason' => 'Provider webhook signature did not match the configured shared secret.',
            ];
        }

        return [
            'allowed' => true,
            'verified' => true,
            'mode' => 'signed',
        ];
    }

    private function resolveVoucherFromPayload(array $payload): Voucher
    {
        $voucherCode = trim((string) ($payload['voucher_code'] ?? $payload['code'] ?? ''));
        $voucherId = $payload['voucher_id'] ?? null;
        $providerReference = trim((string) ($payload['provider_reference'] ?? $payload['reference'] ?? ''));

        $query = Voucher::query()->with(['user', 'venue', 'providerEvents']);

        if ($voucherId) {
            $voucher = (clone $query)->where('id', (int) $voucherId)->first();
            if ($voucher) {
                return $voucher;
            }
        }

        if ($voucherCode !== '') {
            $voucher = (clone $query)->where('code', $voucherCode)->first();
            if ($voucher) {
                return $voucher;
            }
        }

        if ($providerReference !== '') {
            $voucher = (clone $query)->where('external_reference', $providerReference)->first();
            if ($voucher) {
                return $voucher;
            }
        }

        throw new RuntimeException('Voucher could not be resolved from the provider webhook payload.');
    }

    private function normaliseWebhookEventType(string $eventType): string
    {
        $normalised = strtolower(trim($eventType));

        return match ($normalised) {
            'ride_completed', 'ride.completed', 'trip_completed', 'trip.completed' => 'ride_completed',
            'order_completed', 'order.completed', 'delivery_completed', 'delivery.completed' => 'order_completed',
            'order_cancelled', 'order.cancelled', 'delivery_cancelled', 'delivery.cancelled' => 'order_cancelled',
            'destination_mismatch', 'destination.mismatch' => 'destination_mismatch',
            'ride_terminated_early', 'ride.terminated_early', 'ride_ended_early' => 'ride_terminated_early',
            default => throw new RuntimeException('Unsupported provider webhook event type.'),
        };
    }

    private function confirmCompletion(Voucher $voucher, string $eventType, array $attributes): array
    {
        if ($voucher->status === 'redeemed') {
            $latest = $voucher->providerEvents()->latest('occurred_at')->latest('id')->first();

            return [
                'voucher' => $this->voucherPayload($voucher->fresh(['user', 'venue', 'providerEvents'])),
                'event' => $latest ? $this->eventPayload($latest) : null,
                'low_balance_alert' => null,
            ];
        }

        if ($voucher->status === 'cancelled') {
            throw ValidationException::withMessages([
                'voucher' => ['This voucher has already been cancelled and cannot be confirmed.'],
            ]);
        }

        $merchant = $voucher->merchant()->with('wallet')->firstOrFail();
        $wallet = $merchant->wallet;
        $charge = (float) $voucher->total_charge;
        $before = (float) $wallet->balance;

        if ($before < $charge) {
            throw ValidationException::withMessages([
                'wallet_balance' => ['Insufficient wallet balance to settle this verified conversion. Top up and retry provider confirmation.'],
            ]);
        }

        $providerReference = $this->providerReference($voucher, $attributes['provider_reference'] ?? null);
        $destinationMatch = $voucher->journey_type === 'food'
            ? null
            : (bool) ($attributes['destination_match'] ?? true);
        $notes = $this->resolveNotes($attributes['notes'] ?? null, $eventType);

        $result = DB::transaction(function () use ($voucher, $merchant, $wallet, $charge, $before, $providerReference, $destinationMatch, $notes, $eventType) {
            $after = $before - $charge;

            $wallet->update([
                'balance' => $after,
            ]);

            $voucher->update([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'external_reference' => $providerReference,
            ]);

            WalletTransaction::create([
                'merchant_id' => $merchant->id,
                'merchant_wallet_id' => $wallet->id,
                'voucher_id' => $voucher->id,
                'type' => 'debit',
                'amount' => $charge,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference' => 'VERIFY-' . strtoupper(Str::random(6)),
                'notes' => 'Wallet charged after provider-confirmed conversion.',
            ]);

            $event = VoucherProviderEvent::create([
                'voucher_id' => $voucher->id,
                'merchant_id' => $merchant->id,
                'user_id' => $voucher->user_id,
                'provider_name' => $this->providerNameForVoucher($voucher),
                'event_type' => $eventType,
                'verification_result' => 'confirmed',
                'provider_reference' => $providerReference,
                'destination_match' => $destinationMatch,
                'charge_applied' => true,
                'amount_charged' => $charge,
                'notes' => $notes,
                'payload' => [
                    'journey_type' => $voucher->journey_type,
                    'voucher_code' => $voucher->code,
                ],
                'occurred_at' => now(),
            ]);

            return [$event, $merchant->fresh(['wallet'])];
        });

        [$event, $freshMerchant] = $result;
        $freshVoucher = $voucher->fresh(['user', 'venue', 'providerEvents']);

        $this->affiliateTrackingService->markVoucherRedeemed($freshVoucher);
        $this->affiliateCommissionService->recordVoucherRedemptionCommission($freshVoucher);
        $alertResult = $this->walletAlertService->notifyIfLowBalance($freshMerchant, 'provider_confirmed_conversion');

        return [
            'voucher' => $this->voucherPayload($freshVoucher),
            'event' => $this->eventPayload($event->fresh(['voucher.venue', 'voucher.user'])),
            'low_balance_alert' => $alertResult,
        ];
    }

    private function cancelWithoutCharge(Voucher $voucher, string $eventType, array $attributes): array
    {
        if ($voucher->status === 'redeemed') {
            throw ValidationException::withMessages([
                'voucher' => ['This voucher has already been settled and cannot be cancelled.'],
            ]);
        }

        $merchant = $voucher->merchant;
        $providerReference = $this->providerReference($voucher, $attributes['provider_reference'] ?? null);
        $notes = $this->resolveNotes($attributes['notes'] ?? null, $eventType);

        $event = DB::transaction(function () use ($voucher, $merchant, $providerReference, $notes, $eventType) {
            $voucher->update([
                'status' => 'cancelled',
                'external_reference' => $providerReference,
            ]);

            return VoucherProviderEvent::create([
                'voucher_id' => $voucher->id,
                'merchant_id' => $merchant?->id,
                'user_id' => $voucher->user_id,
                'provider_name' => $this->providerNameForVoucher($voucher),
                'event_type' => $eventType,
                'verification_result' => 'cancelled',
                'provider_reference' => $providerReference,
                'destination_match' => null,
                'charge_applied' => false,
                'amount_charged' => null,
                'notes' => $notes,
                'payload' => [
                    'journey_type' => $voucher->journey_type,
                    'voucher_code' => $voucher->code,
                ],
                'occurred_at' => now(),
            ]);
        });

        if ($eventType === 'ride_terminated_early') {
            $this->fraudPreventionService->recordProviderIncident(
                $voucher->fresh(['user']),
                'ride_terminated_early',
                'Provider reported an early ride termination for this voucher.',
                20,
                'medium',
                ['provider_reference' => $providerReference]
            );
        }

        return [
            'voucher' => $this->voucherPayload($voucher->fresh(['user', 'venue', 'providerEvents'])),
            'event' => $this->eventPayload($event->fresh(['voucher.venue', 'voucher.user'])),
            'low_balance_alert' => null,
        ];
    }

    private function rejectMismatch(Voucher $voucher, string $eventType, array $attributes): array
    {
        if ($voucher->status === 'redeemed') {
            throw ValidationException::withMessages([
                'voucher' => ['This voucher has already been settled and cannot be marked as mismatched.'],
            ]);
        }

        $merchant = $voucher->merchant;
        $providerReference = $this->providerReference($voucher, $attributes['provider_reference'] ?? null);
        $notes = $this->resolveNotes($attributes['notes'] ?? null, $eventType);

        $event = DB::transaction(function () use ($voucher, $merchant, $providerReference, $notes, $eventType) {
            $voucher->update([
                'status' => 'cancelled',
                'external_reference' => $providerReference,
            ]);

            return VoucherProviderEvent::create([
                'voucher_id' => $voucher->id,
                'merchant_id' => $merchant?->id,
                'user_id' => $voucher->user_id,
                'provider_name' => $this->providerNameForVoucher($voucher),
                'event_type' => $eventType,
                'verification_result' => 'rejected',
                'provider_reference' => $providerReference,
                'destination_match' => false,
                'charge_applied' => false,
                'amount_charged' => null,
                'notes' => $notes,
                'payload' => [
                    'journey_type' => $voucher->journey_type,
                    'voucher_code' => $voucher->code,
                ],
                'occurred_at' => now(),
            ]);
        });

        $this->fraudPreventionService->recordProviderIncident(
            $voucher->fresh(['user']),
            'destination_mismatch',
            'Provider reported a destination mismatch for this voucher.',
            35,
            'high',
            [
                'provider_reference' => $providerReference,
                'destination_match' => false,
            ]
        );

        return [
            'voucher' => $this->voucherPayload($voucher->fresh(['user', 'venue', 'providerEvents'])),
            'event' => $this->eventPayload($event->fresh(['voucher.venue', 'voucher.user'])),
            'low_balance_alert' => null,
        ];
    }

    private function providerReference(Voucher $voucher, ?string $providedReference): string
    {
        $providedReference = is_string($providedReference) ? trim($providedReference) : '';
        if ($providedReference !== '') {
            return $providedReference;
        }

        $prefix = $voucher->journey_type === 'food' ? 'UE' : 'UB';

        return $prefix . '-' . strtoupper(Str::random(10));
    }

    private function resolveNotes(?string $notes, string $eventType): string
    {
        $notes = is_string($notes) ? trim($notes) : '';
        if ($notes !== '') {
            return $notes;
        }

        return match ($eventType) {
            'ride_completed' => 'Ride completed and verified to the correct destination.',
            'order_completed' => 'Order completed and not cancelled by the provider.',
            'order_cancelled' => 'Order was cancelled before completion. No wallet charge applied.',
            'ride_terminated_early' => 'Ride ended early. Wallet charge was not applied.',
            'destination_mismatch' => 'Ride destination did not match the configured venue postcode.',
            default => 'Provider event logged.',
        };
    }
}
