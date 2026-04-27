<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateCommissionEvent;
use App\Models\AffiliateReferral;
use App\Models\User;
use App\Models\Voucher;
use App\Services\WhatsApp\ThreeSixtyDialogService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class AffiliateCommissionService
{
    public function __construct(private readonly ThreeSixtyDialogService $threeSixtyDialogService)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('talktocas.affiliates.enabled', true);
    }

    public function recordVoucherRedemptionCommission(Voucher $voucher): ?AffiliateCommissionEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        $voucher->loadMissing(['user', 'venue', 'merchant']);
        $referredUser = $voucher->user;
        if (! $referredUser || ! $referredUser->referred_by_user_id) {
            return null;
        }

        $referral = AffiliateReferral::with(['affiliateUser', 'referredUser'])
            ->where('referred_user_id', $referredUser->id)
            ->where('affiliate_user_id', $referredUser->referred_by_user_id)
            ->first();

        if (! $referral) {
            return null;
        }

        $earnedAt = $voucher->redeemed_at ?? now();
        if ($referral->attributed_until && $earnedAt->greaterThan($referral->attributed_until)) {
            return null;
        }

        $event = AffiliateCommissionEvent::firstOrCreate(
            ['voucher_id' => $voucher->id],
            [
                'affiliate_user_id' => $referral->affiliate_user_id,
                'affiliate_referral_id' => $referral->id,
                'referred_user_id' => $referral->referred_user_id,
                'referral_code' => $referral->referral_code,
                'event_type' => 'redeemed_voucher_commission',
                'status' => 'earned',
                'commission_amount' => $this->commissionPerRedeemedVoucher(),
                'earned_at' => $earnedAt,
                'metadata' => [
                    'voucher_code' => $voucher->code,
                    'journey_type' => $voucher->journey_type,
                    'venue_name' => $voucher->venue?->name,
                    'merchant_name' => $voucher->merchant?->business_name,
                    'referred_user_name' => $referredUser->name,
                ],
            ]
        );

        if ($event->wasRecentlyCreated) {
            $this->notifyCommissionEarned($event->fresh(['affiliateUser', 'referredUser', 'voucher']));
        }

        return $event->fresh(['affiliateUser', 'referredUser', 'voucher']);
    }

    public function dashboardForUser(User $user, int $recentLimit = 5): array
    {
        $events = AffiliateCommissionEvent::with(['referredUser', 'voucher'])
            ->where('affiliate_user_id', $user->id)
            ->orderByDesc('earned_at')
            ->get();

        $earnedEvents = $events->where('status', 'earned');
        $paidEvents = $events->where('status', 'paid');
        $pendingEvents = $events->whereNotIn('status', ['paid', 'cancelled']);
        $earnedTotal = (float) $earnedEvents->sum('commission_amount');
        $paidTotal = (float) $paidEvents->sum('commission_amount');
        $pendingTotal = (float) $pendingEvents->sum('commission_amount');
        $averageCommission = $events->count() > 0 ? round(((float) $events->avg('commission_amount')), 2) : 0.0;
        $latestEarnedAt = optional($events->first()?->earned_at)?->toIso8601String();
        $latestPaidAt = optional($paidEvents->sortByDesc('paid_at')->first()?->paid_at)?->toIso8601String();
        $lastNotifiedEvent = $events->filter(fn (AffiliateCommissionEvent $event) => filled($event->notified_at))->sortByDesc('notified_at')->first();
        $emailNotified = $events->filter(fn (AffiliateCommissionEvent $event) => in_array('email', $event->notification_channels ?? [], true))->count();
        $whatsAppNotified = $events->filter(fn (AffiliateCommissionEvent $event) => in_array('whatsapp', $event->notification_channels ?? [], true))->count();

        return [
            'enabled' => $this->enabled(),
            'commission_per_redeemed_voucher' => number_format($this->commissionPerRedeemedVoucher(), 2, '.', ''),
            'summary' => [
                'earned_events' => $earnedEvents->count(),
                'paid_events' => $paidEvents->count(),
                'pending_events' => $pendingEvents->count(),
                'earned_total' => number_format($earnedTotal, 2, '.', ''),
                'paid_total' => number_format($paidTotal, 2, '.', ''),
                'pending_total' => number_format($pendingTotal, 2, '.', ''),
                'average_commission' => number_format($averageCommission, 2, '.', ''),
                'latest_earned_at' => $latestEarnedAt,
                'latest_paid_at' => $latestPaidAt,
            ],
            'notification_summary' => [
                'email_events' => $emailNotified,
                'whatsapp_events' => $whatsAppNotified,
                'last_notified_at' => optional($lastNotifiedEvent?->notified_at)?->toIso8601String(),
            ],
            'recent_events' => $events->take($recentLimit)->map(function (AffiliateCommissionEvent $event) {
                return [
                    'id' => $event->id,
                    'status' => $event->status,
                    'event_type' => $event->event_type,
                    'commission_amount' => number_format((float) $event->commission_amount, 2, '.', ''),
                    'earned_at' => optional($event->earned_at)?->toIso8601String(),
                    'paid_at' => optional($event->paid_at)?->toIso8601String(),
                    'notified_at' => optional($event->notified_at)?->toIso8601String(),
                    'notification_channels' => $event->notification_channels ?? [],
                    'voucher' => $event->voucher ? [
                        'id' => $event->voucher->id,
                        'code' => $event->voucher->code,
                        'journey_type' => $event->voucher->journey_type,
                    ] : null,
                    'referred_user' => $event->referredUser ? [
                        'id' => $event->referredUser->id,
                        'name' => $event->referredUser->name,
                        'email' => $event->referredUser->email,
                    ] : null,
                    'metadata' => $event->metadata ?? [],
                ];
            })->values(),
            'weekly_reports' => $this->weeklyReports($events, $this->reportWeeks()),
            'monthly_reports' => $this->monthlyReports($events, 3),
        ];
    }

    public function sendTestAlert(User $user): array
    {
        $payload = $this->dashboardForUser($user, 3);
        $summary = $payload['summary'];
        $report = $payload['weekly_reports'][0] ?? null;
        $channels = [];

        $emailBody = trim(sprintf(
            "This is a localhost affiliate alert test from TALK to CAS.\n\nAffiliate: %s\nPending commission: £%s\nEarned total: £%s\nThis week events: %s\nThis week total: £%s\n\nCheck your dashboard for payout readiness and recent commission activity.",
            $user->name,
            $summary['pending_total'] ?? '0.00',
            $summary['earned_total'] ?? '0.00',
            $report['earned_events'] ?? 0,
            $report['earned_total'] ?? '0.00',
        ));

        $whatsAppBody = trim(sprintf(
            "TALK to CAS affiliate alert test.\n%s\nPending £%s\nEarned total £%s\nThis week: %s events / £%s",
            $user->name,
            $summary['pending_total'] ?? '0.00',
            $summary['earned_total'] ?? '0.00',
            $report['earned_events'] ?? 0,
            $report['earned_total'] ?? '0.00',
        ));

        if ($user->email) {
            Mail::raw($emailBody, function ($message) use ($user) {
                $message->to($user->email)->subject('TALK to CAS affiliate alert test');
            });
            $channels[] = 'email';
        }

        if ($user->phone) {
            $this->threeSixtyDialogService->sendText($user->phone, $whatsAppBody);
            $channels[] = 'whatsapp';
        }

        return [
            'sent' => count($channels) > 0,
            'channels' => $channels,
            'summary' => $summary,
            'latest_week' => $report,
        ];
    }

    private function notifyCommissionEarned(AffiliateCommissionEvent $event): void
    {
        if (! (bool) config('talktocas.affiliates.immediate_notifications_enabled', true)) {
            return;
        }

        $event->loadMissing(['affiliateUser', 'referredUser', 'voucher']);
        $affiliateUser = $event->affiliateUser;
        if (! $affiliateUser) {
            return;
        }

        $channels = [];
        $amount = number_format((float) $event->commission_amount, 2, '.', '');
        $venueName = $event->metadata['venue_name'] ?? 'a partner venue';
        $voucherCode = $event->voucher?->code ?? ($event->metadata['voucher_code'] ?? 'voucher');
        $referredName = $event->referredUser?->name ?? ($event->metadata['referred_user_name'] ?? 'your referral');

        if ($affiliateUser->email) {
            Mail::raw(
                sprintf(
                    "Good news — you earned a TALK to CAS referral commission.\n\nAmount: £%s\nReferral: %s\nVoucher: %s\nVenue: %s\nEarned at: %s\n\nThis event is now tracked in your affiliate dashboard. Check payout readiness there whenever you need to review settlement progress.",
                    $amount,
                    $referredName,
                    $voucherCode,
                    $venueName,
                    optional($event->earned_at)->format('d M Y H:i')
                ),
                function ($message) use ($affiliateUser) {
                    $message->to($affiliateUser->email)->subject('TALK to CAS commission earned');
                }
            );
            $channels[] = 'email';
        }

        if ($affiliateUser->phone) {
            $this->threeSixtyDialogService->sendText(
                $affiliateUser->phone,
                sprintf(
                    'TALK to CAS: you earned £%s commission. %s redeemed voucher %s for %s.',
                    $amount,
                    $referredName,
                    $voucherCode,
                    $venueName
                )
            );
            $channels[] = 'whatsapp';
        }

        if ($channels !== []) {
            $event->forceFill([
                'notified_at' => now(),
                'notification_channels' => $channels,
            ])->save();
        }
    }

    private function commissionPerRedeemedVoucher(): float
    {
        return max(0, (float) config('talktocas.affiliates.commission_per_redeemed_voucher', 0));
    }

    private function reportWeeks(): int
    {
        return max(1, (int) config('talktocas.affiliates.report_weeks', 6));
    }


    private function monthlyReports(Collection $events, int $months): array
    {
        $reports = [];

        for ($offset = 0; $offset < $months; $offset++) {
            $monthStart = Carbon::now()->startOfMonth()->subMonths($offset);
            $monthEnd = $monthStart->copy()->endOfMonth();

            $monthEvents = $events->filter(function (AffiliateCommissionEvent $event) use ($monthStart, $monthEnd) {
                return $event->earned_at && $event->earned_at->betweenIncluded($monthStart, $monthEnd);
            });

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

    private function weeklyReports(Collection $events, int $weeks): array
    {
        $reports = [];

        for ($offset = 0; $offset < $weeks; $offset++) {
            $weekStart = Carbon::now()->startOfWeek()->subWeeks($offset);
            $weekEnd = $weekStart->copy()->endOfWeek();

            $weekEvents = $events->filter(function (AffiliateCommissionEvent $event) use ($weekStart, $weekEnd) {
                return $event->earned_at && $event->earned_at->betweenIncluded($weekStart, $weekEnd);
            });

            $earnedTotal = (float) $weekEvents->sum('commission_amount');
            $paidTotal = (float) $weekEvents->where('status', 'paid')->sum('commission_amount');
            $pendingTotal = (float) $weekEvents->whereNotIn('status', ['paid', 'cancelled'])->sum('commission_amount');

            $reports[] = [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'earned_events' => $weekEvents->count(),
                'earned_total' => number_format($earnedTotal, 2, '.', ''),
                'paid_total' => number_format($paidTotal, 2, '.', ''),
                'pending_total' => number_format($pendingTotal, 2, '.', ''),
            ];
        }

        return $reports;
    }
}
