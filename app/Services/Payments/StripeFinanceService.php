<?php

namespace App\Services\Payments;

use App\Models\AffiliateCommissionEvent;
use App\Models\AffiliatePayoutProfile;
use App\Models\Merchant;
use App\Models\StripePayoutRun;
use App\Models\StripeTopUpIntent;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class StripeFinanceService
{
    public function merchantPayload(Merchant $merchant, int $recentLimit = 5): array
    {
        $wallet = $merchant->wallet;
        $intentQuery = StripeTopUpIntent::query()->where('merchant_id', $merchant->id);
        $recentIntents = (clone $intentQuery)
            ->latest()
            ->take($recentLimit)
            ->get();

        $successfulTotal = (float) (clone $intentQuery)
            ->where('status', 'succeeded')
            ->sum('amount');

        return [
            'enabled' => $this->enabled(),
            'provider' => 'stripe',
            'publishable_key_configured' => filled((string) config('services.stripe.publishable_key')),
            'default_currency' => $this->defaultCurrency(),
            'allow_simulated_success' => $this->allowSimulatedSuccess(),
            'top_up_base_url' => $this->merchantTopUpBaseUrl(),
            'auto_top_up_ready' => (bool) ($wallet?->auto_top_up_enabled) && $this->enabled(),
            'webhooks' => [
                'enabled' => $this->webhooksEnabled(),
                'allow_unsigned_localhost' => $this->allowUnsignedLocalhostWebhook(),
                'require_valid_signature' => $this->signatureVerificationRequired(),
                'secret_configured' => $this->webhookSecretConfigured(),
                'endpoint_url' => $this->stripeWebhookUrl(),
            ],
            'summary' => [
                'pending_count' => (clone $intentQuery)->where('status', 'pending')->count(),
                'successful_count' => (clone $intentQuery)->where('status', 'succeeded')->count(),
                'failed_count' => (clone $intentQuery)->where('status', 'failed')->count(),
                'successful_total' => number_format($successfulTotal, 2, '.', ''),
            ],
            'recent_intents' => $recentIntents->map(fn (StripeTopUpIntent $intent) => $this->topUpIntentPayload($intent))->values(),
        ];
    }

    public function createTopUpIntent(Merchant $merchant, float $amount, string $mode = 'manual'): StripeTopUpIntent
    {
        $wallet = $merchant->wallet;
        if (! $wallet) {
            throw new RuntimeException('Merchant wallet not found.');
        }

        $checkoutCode = $this->generateUniqueCheckoutCode();

        return StripeTopUpIntent::create([
            'merchant_id' => $merchant->id,
            'merchant_wallet_id' => $wallet->id,
            'amount' => round($amount, 2),
            'currency' => $this->defaultCurrency(),
            'mode' => $mode,
            'provider' => 'stripe',
            'checkout_code' => $checkoutCode,
            'status' => 'pending',
            'stripe_payment_intent_id' => 'pi_sim_' . strtolower(Str::random(18)),
            'metadata' => [
                'merchant_name' => $merchant->business_name,
                'simulated' => true,
                'checkout_url' => rtrim($this->merchantTopUpBaseUrl(), '/') . '/' . $checkoutCode,
            ],
        ]);
    }

    public function topUpIntentPayload(StripeTopUpIntent $intent): array
    {
        return [
            'id' => $intent->id,
            'checkout_code' => $intent->checkout_code,
            'amount' => number_format((float) $intent->amount, 2, '.', ''),
            'currency' => $intent->currency,
            'mode' => $intent->mode,
            'provider' => $intent->provider,
            'status' => $intent->status,
            'stripe_payment_intent_id' => $intent->stripe_payment_intent_id,
            'simulated_payment_reference' => $intent->simulated_payment_reference,
            'checkout_url' => Arr::get($intent->metadata ?? [], 'checkout_url'),
            'paid_at' => optional($intent->paid_at)?->toIso8601String(),
            'created_at' => optional($intent->created_at)?->toIso8601String(),
        ];
    }

    public function simulateTopUpSuccess(Merchant $merchant, string $checkoutCode): array
    {
        if (! $this->allowSimulatedSuccess()) {
            throw new RuntimeException('Simulated Stripe settlement is disabled in this environment.');
        }

        $intent = StripeTopUpIntent::query()
            ->where('merchant_id', $merchant->id)
            ->where('checkout_code', strtoupper(trim($checkoutCode)))
            ->firstOrFail();

        if ($intent->status === 'succeeded') {
            return [
                'intent' => $intent->fresh(),
                'wallet_balance' => number_format((float) $merchant->wallet?->balance, 2, '.', ''),
                'already_processed' => true,
            ];
        }

        $settledIntent = $this->settleTopUpIntent(
            $intent,
            'STP-SIM-' . strtoupper(Str::random(8)),
            'simulated_localhost'
        );

        return [
            'intent' => $settledIntent,
            'wallet_balance' => number_format((float) $merchant->wallet?->fresh()?->balance, 2, '.', ''),
            'already_processed' => false,
        ];
    }

    public function payoutProfilePayload(User $user, int $recentLimit = 5): array
    {
        $profile = $this->ensurePayoutProfile($user);
        $events = AffiliateCommissionEvent::query()
            ->with(['voucher', 'referredUser'])
            ->where('affiliate_user_id', $user->id)
            ->latest('earned_at')
            ->get();

        $recentPayouts = StripePayoutRun::query()
            ->where('user_id', $user->id)
            ->latest()
            ->take($recentLimit)
            ->get();

        $pendingEvents = $events->whereNotIn('status', ['paid', 'cancelled']);
        $readyEvents = $pendingEvents->filter(fn (AffiliateCommissionEvent $event) => blank($event->payout_run_id));
        $paidEvents = $events->where('status', 'paid');
        $latestWeekStart = Carbon::now()->startOfWeek();
        $latestWeekEnd = $latestWeekStart->copy()->endOfWeek();
        $thisWeekEvents = $events->filter(function (AffiliateCommissionEvent $event) use ($latestWeekStart, $latestWeekEnd) {
            return $event->earned_at && $event->earned_at->betweenIncluded($latestWeekStart, $latestWeekEnd);
        });
        $averageCommission = $events->count() > 0 ? round((float) $events->avg('commission_amount'), 2) : 0.0;
        $emailNotified = $events->filter(fn (AffiliateCommissionEvent $event) => in_array('email', $event->notification_channels ?? [], true))->count();
        $whatsAppNotified = $events->filter(fn (AffiliateCommissionEvent $event) => in_array('whatsapp', $event->notification_channels ?? [], true))->count();
        $lastNotifiedEvent = $events->filter(fn (AffiliateCommissionEvent $event) => filled($event->notified_at))->sortByDesc('notified_at')->first();
        $payoutHealth = $this->payoutHealthPayload($profile, $readyEvents->count(), $recentPayouts->where('status', 'processing')->count());

        return [
            'enabled' => $this->enabled(),
            'provider' => $profile->provider,
            'payout_email' => $profile->payout_email,
            'country_code' => $profile->country_code,
            'currency' => $profile->currency,
            'onboarding_status' => $profile->onboarding_status,
            'stripe_account_id' => $profile->stripe_account_id,
            'charges_enabled' => (bool) $profile->charges_enabled,
            'payouts_enabled' => (bool) $profile->payouts_enabled,
            'details_submitted_at' => optional($profile->details_submitted_at)?->toIso8601String(),
            'approved_at' => optional($profile->approved_at)?->toIso8601String(),
            'allow_simulated_success' => $this->allowSimulatedSuccess(),
            'webhook_ready' => $this->webhooksEnabled(),
            'onboarding_url' => $this->connectBaseUrl() . '?affiliate=' . urlencode($user->affiliateProfile?->share_code ?? (string) $user->id),
            'summary' => [
                'pending_events' => $pendingEvents->count(),
                'pending_total' => number_format((float) $pendingEvents->sum('commission_amount'), 2, '.', ''),
                'ready_events' => $readyEvents->count(),
                'ready_total' => number_format((float) $readyEvents->sum('commission_amount'), 2, '.', ''),
                'paid_total' => number_format((float) $paidEvents->sum('commission_amount'), 2, '.', ''),
                'latest_week_total' => number_format((float) $thisWeekEvents->sum('commission_amount'), 2, '.', ''),
                'latest_week_events' => $thisWeekEvents->count(),
                'processing_runs' => $recentPayouts->where('status', 'processing')->count(),
                'paid_runs' => $recentPayouts->where('status', 'paid')->count(),
                'average_commission' => number_format($averageCommission, 2, '.', ''),
                'latest_paid_at' => optional($paidEvents->sortByDesc('paid_at')->first()?->paid_at)?->toIso8601String(),
            ],
            'notification_summary' => [
                'email_events' => $emailNotified,
                'whatsapp_events' => $whatsAppNotified,
                'last_notified_at' => optional($lastNotifiedEvent?->notified_at)?->toIso8601String(),
            ],
            'payout_health' => $payoutHealth,
            'recent_commissions' => $events->take($recentLimit)->map(function (AffiliateCommissionEvent $event) {
                return [
                    'id' => $event->id,
                    'status' => $event->status,
                    'commission_amount' => number_format((float) $event->commission_amount, 2, '.', ''),
                    'earned_at' => optional($event->earned_at)?->toIso8601String(),
                    'paid_at' => optional($event->paid_at)?->toIso8601String(),
                    'voucher_code' => $event->voucher?->code ?? Arr::get($event->metadata ?? [], 'voucher_code'),
                    'venue_name' => Arr::get($event->metadata ?? [], 'venue_name'),
                    'referred_user_name' => $event->referredUser?->name ?? Arr::get($event->metadata ?? [], 'referred_user_name'),
                ];
            })->values(),
            'recent_payouts' => $recentPayouts->map(fn (StripePayoutRun $run) => $this->payoutRunPayload($run))->values(),
            'weekly_reports' => $this->commissionWeeklyReports($events, 6),
            'monthly_reports' => $this->commissionMonthlyReports($events, 3),
        ];
    }

    public function updatePayoutProfile(User $user, array $attributes): AffiliatePayoutProfile
    {
        $profile = $this->ensurePayoutProfile($user);

        $profile->update([
            'payout_email' => $attributes['payout_email'] ?? $profile->payout_email,
            'country_code' => strtoupper((string) ($attributes['country_code'] ?? $profile->country_code ?? $this->defaultCountryCode())),
            'currency' => strtoupper((string) ($attributes['currency'] ?? $profile->currency ?? $this->defaultCurrency())),
            'metadata' => array_merge($profile->metadata ?? [], [
                'last_profile_update_at' => now()->toIso8601String(),
            ]),
        ]);

        return $profile->fresh();
    }

    public function startAffiliateOnboarding(User $user): AffiliatePayoutProfile
    {
        $profile = $this->ensurePayoutProfile($user);

        $profile->forceFill([
            'onboarding_status' => 'pending',
            'stripe_account_id' => $profile->stripe_account_id ?: 'acct_sim_' . strtolower(Str::random(16)),
            'details_submitted_at' => now(),
            'metadata' => array_merge($profile->metadata ?? [], [
                'onboarding_started_at' => now()->toIso8601String(),
                'simulated_localhost' => true,
            ]),
        ])->save();

        return $profile->fresh();
    }

    public function simulateAffiliateApproval(User $user): AffiliatePayoutProfile
    {
        if (! $this->allowSimulatedSuccess()) {
            throw new RuntimeException('Simulated Stripe Connect approval is disabled in this environment.');
        }

        $profile = $this->ensurePayoutProfile($user);
        $profile->forceFill([
            'onboarding_status' => 'approved',
            'stripe_account_id' => $profile->stripe_account_id ?: 'acct_sim_' . strtolower(Str::random(16)),
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted_at' => $profile->details_submitted_at ?: now(),
            'approved_at' => now(),
            'metadata' => array_merge($profile->metadata ?? [], [
                'approved_via' => 'simulated_localhost',
                'approved_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return $profile->fresh();
    }

    public function createAffiliatePayoutRun(User $user): StripePayoutRun
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Stripe finance is disabled.');
        }

        $profile = $this->ensurePayoutProfile($user);
        if (! $profile->charges_enabled || ! $profile->payouts_enabled || $profile->onboarding_status !== 'approved') {
            throw new RuntimeException('Complete Stripe Connect onboarding before creating a payout run.');
        }

        $events = AffiliateCommissionEvent::query()
            ->where('affiliate_user_id', $user->id)
            ->whereNull('payout_run_id')
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->orderBy('earned_at')
            ->get();

        if ($events->isEmpty()) {
            throw new RuntimeException('There are no payout-ready commission events yet.');
        }

        return DB::transaction(function () use ($user, $profile, $events) {
            $run = StripePayoutRun::create([
                'user_id' => $user->id,
                'affiliate_payout_profile_id' => $profile->id,
                'provider' => 'stripe_connect',
                'payout_code' => $this->generateUniquePayoutCode(),
                'status' => 'processing',
                'currency' => $profile->currency ?: $this->defaultCurrency(),
                'total_amount' => round((float) $events->sum('commission_amount'), 2),
                'commission_event_count' => $events->count(),
                'stripe_payout_id' => 'po_sim_' . strtolower(Str::random(18)),
                'metadata' => [
                    'created_at' => now()->toIso8601String(),
                    'event_ids' => $events->pluck('id')->values()->all(),
                    'simulated_localhost' => true,
                ],
            ]);

            foreach ($events as $event) {
                $event->forceFill([
                    'payout_run_id' => $run->id,
                    'status' => 'payout_pending',
                    'metadata' => array_merge($event->metadata ?? [], [
                        'payout_code' => $run->payout_code,
                        'queued_for_payout_at' => now()->toIso8601String(),
                    ]),
                ])->save();
            }

            return $run->fresh();
        });
    }

    public function simulateAffiliatePayout(User $user, string $payoutCode): array
    {
        if (! $this->allowSimulatedSuccess()) {
            throw new RuntimeException('Simulated Stripe payouts are disabled in this environment.');
        }

        $run = StripePayoutRun::query()
            ->where('user_id', $user->id)
            ->where('payout_code', strtoupper(trim($payoutCode)))
            ->firstOrFail();

        if ($run->status === 'paid') {
            return [
                'run' => $run->fresh(),
                'already_processed' => true,
            ];
        }

        return [
            'run' => $this->markPayoutRunPaid(
                $run,
                'PAYOUT-SIM-' . strtoupper(Str::random(8)),
                'simulated_localhost'
            ),
            'already_processed' => false,
        ];
    }

    public function handleWebhookPayload(array $payload, array $context = []): array
    {
        if (! $this->webhooksEnabled()) {
            return [
                'handled' => false,
                'reason' => 'Stripe webhooks are disabled in config.',
            ];
        }

        $verification = $this->verifyWebhookSignature(
            $context['stripe-signature'] ?? null,
            (string) ($context['raw_payload'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );

        if ($this->signatureVerificationRequired() && ! $verification['valid']) {
            throw new RuntimeException($verification['reason'] ?? 'Invalid Stripe webhook signature.');
        }

        if (! $verification['valid'] && ! $this->allowUnsignedLocalhostWebhook()) {
            throw new RuntimeException($verification['reason'] ?? 'Unsigned Stripe webhooks are not allowed in this environment.');
        }

        $eventType = (string) ($payload['type'] ?? $payload['event_type'] ?? 'unknown');
        $object = Arr::get($payload, 'data.object', []);

        $result = match ($eventType) {
            'payment_intent.succeeded' => $this->handleTopUpSuccessWebhook($object, $eventType),
            'payment_intent.payment_failed', 'charge.failed' => $this->handleTopUpFailedWebhook($object, $eventType),
            'account.updated' => $this->handleAccountUpdatedWebhook($object, $eventType),
            'payout.paid', 'transfer.paid' => $this->handlePayoutPaidWebhook($object, $eventType),
            default => [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'No Stripe webhook handler is registered for this event yet.',
            ],
        };

        $result['signature_valid'] = $verification['valid'];
        $result['verification_mode'] = $verification['mode'];

        return $result;
    }

    public function enabled(): bool
    {
        return (bool) config('talktocas.stripe_finance.enabled', true);
    }

    public function allowSimulatedSuccess(): bool
    {
        return (bool) config('talktocas.stripe_finance.allow_simulated_success', true);
    }

    public function webhooksEnabled(): bool
    {
        return (bool) config('talktocas.stripe_finance.webhooks.enabled', true);
    }

    public function allowUnsignedLocalhostWebhook(): bool
    {
        return (bool) config('talktocas.stripe_finance.webhooks.allow_unsigned_localhost', true);
    }

    public function signatureVerificationRequired(): bool
    {
        return (bool) config('talktocas.stripe_finance.webhooks.require_valid_signature', false)
            || (bool) config('talktocas.operations.live_mode', false);
    }

    public function webhookSecretConfigured(): bool
    {
        return filled(config('services.stripe.webhook_secret'));
    }


    private function payoutHealthPayload(AffiliatePayoutProfile $profile, int $readyEvents, int $processingRuns): array
    {
        if ($processingRuns > 0) {
            return [
                'status' => 'processing',
                'label' => 'Payout processing',
                'next_action' => 'Wait for webhook settlement or localhost simulation to mark the open payout run as paid.',
            ];
        }

        if ($profile->onboarding_status === 'not_started') {
            return [
                'status' => 'setup_required',
                'label' => 'Setup required',
                'next_action' => 'Save payout details and start Stripe Connect onboarding before creating payout runs.',
            ];
        }

        if ($profile->onboarding_status !== 'approved' || ! $profile->charges_enabled || ! $profile->payouts_enabled) {
            return [
                'status' => 'onboarding_pending',
                'label' => 'Onboarding pending',
                'next_action' => 'Complete and approve Stripe Connect onboarding so payout-ready commission events can be settled.',
            ];
        }

        if ($readyEvents > 0) {
            return [
                'status' => 'payout_ready',
                'label' => 'Payout ready',
                'next_action' => 'Create a payout run now. The current commission queue is ready for settlement.',
            ];
        }

        return [
            'status' => 'collecting',
            'label' => 'Collecting commissions',
            'next_action' => 'No payout-ready events yet. Keep sharing your affiliate link while new redemptions come in.',
        ];
    }

    private function commissionWeeklyReports(Collection $events, int $weeks): array
    {
        $reports = [];

        for ($offset = 0; $offset < $weeks; $offset++) {
            $weekStart = Carbon::now()->startOfWeek()->subWeeks($offset);
            $weekEnd = $weekStart->copy()->endOfWeek();
            $weekEvents = $events->filter(fn (AffiliateCommissionEvent $event) => $event->earned_at && $event->earned_at->betweenIncluded($weekStart, $weekEnd));

            $reports[] = [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'label' => $weekStart->format('d M') . ' - ' . $weekEnd->format('d M'),
                'earned_events' => $weekEvents->count(),
                'earned_total' => number_format((float) $weekEvents->sum('commission_amount'), 2, '.', ''),
                'paid_total' => number_format((float) $weekEvents->where('status', 'paid')->sum('commission_amount'), 2, '.', ''),
                'pending_total' => number_format((float) $weekEvents->whereNotIn('status', ['paid', 'cancelled'])->sum('commission_amount'), 2, '.', ''),
            ];
        }

        return $reports;
    }

    private function commissionMonthlyReports(Collection $events, int $months): array
    {
        $reports = [];

        for ($offset = 0; $offset < $months; $offset++) {
            $monthStart = Carbon::now()->startOfMonth()->subMonths($offset);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $monthEvents = $events->filter(fn (AffiliateCommissionEvent $event) => $event->earned_at && $event->earned_at->betweenIncluded($monthStart, $monthEnd));

            $reports[] = [
                'month_start' => $monthStart->toDateString(),
                'month_end' => $monthEnd->toDateString(),
                'label' => $monthStart->format('M Y'),
                'earned_events' => $monthEvents->count(),
                'earned_total' => number_format((float) $monthEvents->sum('commission_amount'), 2, '.', ''),
                'paid_total' => number_format((float) $monthEvents->where('status', 'paid')->sum('commission_amount'), 2, '.', ''),
                'pending_total' => number_format((float) $monthEvents->whereNotIn('status', ['paid', 'cancelled'])->sum('commission_amount'), 2, '.', ''),
            ];
        }

        return $reports;
    }

    private function ensurePayoutProfile(User $user): AffiliatePayoutProfile
    {
        return AffiliatePayoutProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'provider' => 'stripe_connect',
                'payout_email' => $user->email,
                'country_code' => $this->defaultCountryCode(),
                'currency' => $this->defaultCurrency(),
                'onboarding_status' => 'not_started',
                'charges_enabled' => false,
                'payouts_enabled' => false,
            ]
        );
    }

    private function payoutRunPayload(StripePayoutRun $run): array
    {
        return [
            'id' => $run->id,
            'payout_code' => $run->payout_code,
            'status' => $run->status,
            'provider' => $run->provider,
            'currency' => $run->currency,
            'total_amount' => number_format((float) $run->total_amount, 2, '.', ''),
            'commission_event_count' => (int) $run->commission_event_count,
            'stripe_payout_id' => $run->stripe_payout_id,
            'paid_reference' => $run->paid_reference,
            'paid_at' => optional($run->paid_at)?->toIso8601String(),
            'created_at' => optional($run->created_at)?->toIso8601String(),
        ];
    }

    private function generateUniqueCheckoutCode(): string
    {
        do {
            $code = 'CHK-' . strtoupper(Str::random(10));
        } while (StripeTopUpIntent::query()->where('checkout_code', $code)->exists());

        return $code;
    }

    private function generateUniquePayoutCode(): string
    {
        do {
            $code = 'PAY-' . strtoupper(Str::random(10));
        } while (StripePayoutRun::query()->where('payout_code', $code)->exists());

        return $code;
    }

    private function settleTopUpIntent(StripeTopUpIntent $intent, string $paymentReference, string $settlementMode): StripeTopUpIntent
    {
        if ($intent->status === 'succeeded') {
            return $intent->fresh();
        }

        $merchant = $intent->merchant()->first();
        $wallet = $intent->wallet()->first();

        if (! $merchant || ! $wallet || (int) $wallet->id !== (int) $intent->merchant_wallet_id) {
            throw new RuntimeException('Wallet mismatch for this Stripe top-up intent.');
        }

        return DB::transaction(function () use ($intent, $wallet, $merchant, $paymentReference, $settlementMode) {
            $before = (float) $wallet->balance;
            $after = $before + (float) $intent->amount;

            $wallet->update([
                'balance' => $after,
            ]);

            WalletTransaction::create([
                'merchant_id' => $merchant->id,
                'merchant_wallet_id' => $wallet->id,
                'type' => 'deposit',
                'amount' => $intent->amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference' => $paymentReference,
                'notes' => sprintf('Stripe %s top-up confirmed via %s.', $intent->mode, $settlementMode),
            ]);

            $intent->forceFill([
                'status' => 'succeeded',
                'simulated_payment_reference' => $paymentReference,
                'paid_at' => now(),
                'metadata' => array_merge($intent->metadata ?? [], [
                    'settled_at' => now()->toIso8601String(),
                    'settlement_mode' => $settlementMode,
                ]),
            ])->save();

            return $intent->fresh();
        });
    }

    private function markTopUpIntentFailed(StripeTopUpIntent $intent, string $reason, string $eventType): StripeTopUpIntent
    {
        $intent->forceFill([
            'status' => 'failed',
            'metadata' => array_merge($intent->metadata ?? [], [
                'failed_at' => now()->toIso8601String(),
                'failure_reason' => $reason,
                'failure_event_type' => $eventType,
            ]),
        ])->save();

        return $intent->fresh();
    }

    private function handleTopUpSuccessWebhook(array $object, string $eventType): array
    {
        $paymentIntentId = (string) ($object['id'] ?? $object['payment_intent'] ?? '');
        $checkoutCode = strtoupper((string) Arr::get($object, 'metadata.checkout_code', Arr::get($object, 'checkout_code', '')));
        $intent = $this->findTopUpIntent($paymentIntentId, $checkoutCode);

        if (! $intent) {
            return [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'No Stripe top-up intent matched this webhook payload.',
            ];
        }

        $reference = 'STP-WEB-' . strtoupper(Str::random(8));
        $intent = $this->settleTopUpIntent($intent, $reference, 'stripe_webhook');

        return [
            'handled' => true,
            'event_type' => $eventType,
            'resource' => 'stripe_top_up_intent',
            'status' => $intent->status,
            'intent' => $this->topUpIntentPayload($intent),
        ];
    }

    private function handleTopUpFailedWebhook(array $object, string $eventType): array
    {
        $paymentIntentId = (string) ($object['id'] ?? $object['payment_intent'] ?? '');
        $checkoutCode = strtoupper((string) Arr::get($object, 'metadata.checkout_code', Arr::get($object, 'checkout_code', '')));
        $intent = $this->findTopUpIntent($paymentIntentId, $checkoutCode);

        if (! $intent) {
            return [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'No Stripe top-up intent matched this failed payment webhook.',
            ];
        }

        $failureMessage = (string) Arr::get($object, 'last_payment_error.message', 'Payment failed at Stripe.');
        $intent = $this->markTopUpIntentFailed($intent, $failureMessage, $eventType);

        return [
            'handled' => true,
            'event_type' => $eventType,
            'resource' => 'stripe_top_up_intent',
            'status' => $intent->status,
            'intent' => $this->topUpIntentPayload($intent),
        ];
    }

    private function handleAccountUpdatedWebhook(array $object, string $eventType): array
    {
        $accountId = (string) ($object['id'] ?? '');
        if ($accountId === '') {
            return [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'Stripe account ID was missing from the webhook payload.',
            ];
        }

        $profile = AffiliatePayoutProfile::query()->where('stripe_account_id', $accountId)->first();
        if (! $profile) {
            return [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'No affiliate payout profile matched this Stripe account.',
            ];
        }

        $chargesEnabled = (bool) ($object['charges_enabled'] ?? false);
        $payoutsEnabled = (bool) ($object['payouts_enabled'] ?? false);
        $profile->forceFill([
            'charges_enabled' => $chargesEnabled,
            'payouts_enabled' => $payoutsEnabled,
            'onboarding_status' => $chargesEnabled && $payoutsEnabled ? 'approved' : 'pending',
            'approved_at' => $chargesEnabled && $payoutsEnabled ? ($profile->approved_at ?: now()) : $profile->approved_at,
            'metadata' => array_merge($profile->metadata ?? [], [
                'last_account_webhook_at' => now()->toIso8601String(),
                'last_account_webhook_payload' => [
                    'charges_enabled' => $chargesEnabled,
                    'payouts_enabled' => $payoutsEnabled,
                ],
            ]),
        ])->save();

        return [
            'handled' => true,
            'event_type' => $eventType,
            'resource' => 'affiliate_payout_profile',
            'status' => $profile->onboarding_status,
            'profile_user_id' => $profile->user_id,
        ];
    }

    private function handlePayoutPaidWebhook(array $object, string $eventType): array
    {
        $payoutId = (string) ($object['id'] ?? '');
        $payoutCode = strtoupper((string) Arr::get($object, 'metadata.payout_code', ''));
        $run = StripePayoutRun::query()
            ->when($payoutId !== '', fn ($query) => $query->where('stripe_payout_id', $payoutId))
            ->when($payoutCode !== '', fn ($query) => $query->orWhere('payout_code', $payoutCode))
            ->first();

        if (! $run) {
            return [
                'handled' => false,
                'event_type' => $eventType,
                'reason' => 'No affiliate payout run matched this Stripe payout webhook.',
            ];
        }

        $reference = 'PAYOUT-WEB-' . strtoupper(Str::random(8));
        $run = $this->markPayoutRunPaid($run, $reference, 'stripe_webhook');

        return [
            'handled' => true,
            'event_type' => $eventType,
            'resource' => 'stripe_payout_run',
            'status' => $run->status,
            'payout_run' => $this->payoutRunPayload($run),
        ];
    }

    private function markPayoutRunPaid(StripePayoutRun $run, string $reference, string $mode): StripePayoutRun
    {
        return DB::transaction(function () use ($run, $reference, $mode) {
            $paidAt = now();

            $run->forceFill([
                'status' => 'paid',
                'paid_reference' => $reference,
                'paid_at' => $paidAt,
                'metadata' => array_merge($run->metadata ?? [], [
                    'paid_at' => $paidAt->toIso8601String(),
                    'settlement_mode' => $mode,
                ]),
            ])->save();

            $events = $run->commissionEvents()->whereNotIn('status', ['cancelled'])->get();
            foreach ($events as $event) {
                $event->forceFill([
                    'status' => 'paid',
                    'paid_at' => $paidAt,
                    'metadata' => array_merge($event->metadata ?? [], [
                        'payout_reference' => $reference,
                        'payout_status' => 'paid',
                        'payout_settlement_mode' => $mode,
                    ]),
                ])->save();
            }

            return $run->fresh();
        });
    }

    private function findTopUpIntent(?string $paymentIntentId, ?string $checkoutCode): ?StripeTopUpIntent
    {
        return StripeTopUpIntent::query()
            ->when(filled($paymentIntentId), fn ($query) => $query->where('stripe_payment_intent_id', $paymentIntentId))
            ->when(filled($checkoutCode), function ($query) use ($checkoutCode, $paymentIntentId) {
                if (filled($paymentIntentId)) {
                    $query->orWhere('checkout_code', strtoupper((string) $checkoutCode));
                } else {
                    $query->where('checkout_code', strtoupper((string) $checkoutCode));
                }
            })
            ->first();
    }

    private function defaultCurrency(): string
    {
        return strtoupper((string) config('talktocas.stripe_finance.default_currency', 'GBP'));
    }

    private function defaultCountryCode(): string
    {
        return strtoupper((string) config('talktocas.stripe_finance.default_country_code', 'GB'));
    }

    private function merchantTopUpBaseUrl(): string
    {
        return rtrim((string) config('talktocas.stripe_finance.top_up_base_url', rtrim((string) config('services.frontend.url', config('app.url')), '/') . '/merchant/dashboard'), '/');
    }

    private function connectBaseUrl(): string
    {
        return rtrim((string) config('talktocas.stripe_finance.connect_base_url', rtrim((string) config('services.frontend.url', config('app.url')), '/') . '/dashboard'), '/');
    }

    private function stripeWebhookUrl(): string
    {
        return rtrim((string) config('talktocas.stripe_finance.webhooks.endpoint_url', rtrim((string) config('app.url'), '/') . '/api/v1/stripe/webhook'), '/');
    }

    private function verifyWebhookSignature(?string $signatureHeader, string $rawPayload): array
    {
        $secret = (string) config('services.stripe.webhook_secret', '');

        if (blank($signatureHeader)) {
            return [
                'valid' => false,
                'mode' => 'unsigned',
                'reason' => 'Missing Stripe-Signature header.',
            ];
        }

        if (blank($secret)) {
            return [
                'valid' => false,
                'mode' => 'missing_secret',
                'reason' => 'Missing STRIPE_WEBHOOK_SECRET for signature verification.',
            ];
        }

        $parts = [];
        foreach (explode(',', (string) $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key][] = $value;
            }
        }

        $timestamp = $parts['t'][0] ?? null;
        $versions = $parts['v1'] ?? [];

        if (! $timestamp || empty($versions)) {
            return [
                'valid' => false,
                'mode' => 'malformed_header',
                'reason' => 'Stripe-Signature header is missing t= or v1= values.',
            ];
        }

        $signedPayload = $timestamp . '.' . $rawPayload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);
        $valid = collect($versions)->contains(fn ($candidate) => hash_equals($expected, (string) $candidate));

        return [
            'valid' => $valid,
            'mode' => 'stripe_v1',
            'reason' => $valid ? null : 'Stripe webhook signature mismatch.',
        ];
    }
}

