<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateProfile;
use App\Models\AffiliateReferral;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AffiliateTrackingService
{
    public function enabled(): bool
    {
        return (bool) config('talktocas.affiliates.enabled', true);
    }

    public function ensureProfile(User $user): ?AffiliateProfile
    {
        if (! $this->enabled() || ! $user->isUser()) {
            return null;
        }

        return AffiliateProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['share_code' => $this->generateUniqueShareCode()]
        );
    }

    public function invitePayloadByCode(string $shareCode, bool $trackClick = true): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $profile = AffiliateProfile::with('user')->where('share_code', strtoupper(trim($shareCode)))->first();
        if (! $profile || ! $profile->user) {
            return null;
        }

        if ($trackClick) {
            $profile->increment('clicks');
            $profile->forceFill(['last_shared_at' => now()])->save();
        }

        return $this->invitePayload($profile->fresh('user'));
    }

    public function attachReferredUser(User $referredUser, string $shareCode): ?AffiliateReferral
    {
        if (! $this->enabled()) {
            return null;
        }

        $profile = AffiliateProfile::with('user')->where('share_code', strtoupper(trim($shareCode)))->first();
        if (! $profile || ! $profile->user) {
            return null;
        }

        if ((int) $profile->user_id === (int) $referredUser->id) {
            return null;
        }

        $attributedUntil = now()->addDays($this->attributionDays());

        $referredUser->forceFill([
            'referred_by_user_id' => $profile->user_id,
            'referral_code_used' => $profile->share_code,
            'referral_attributed_until' => $attributedUntil,
        ])->save();

        return AffiliateReferral::updateOrCreate(
            ['referred_user_id' => $referredUser->id],
            [
                'affiliate_profile_id' => $profile->id,
                'affiliate_user_id' => $profile->user_id,
                'referral_code' => $profile->share_code,
                'signed_up_at' => now(),
                'attributed_until' => $attributedUntil,
                'status' => 'signed_up',
                'metadata' => [
                    'affiliate_name' => $profile->user->name,
                ],
            ]
        );
    }

    public function markVoucherIssued(Voucher $voucher): void
    {
        $this->markVoucherLifecycle($voucher, 'issued');
    }

    public function markVoucherRedeemed(Voucher $voucher): void
    {
        $this->markVoucherLifecycle($voucher, 'redeemed');
    }

    public function dashboardForUser(User $user): ?array
    {
        if (! $this->enabled() || ! $user->isUser()) {
            return null;
        }

        $profile = $this->ensureProfile($user);
        if (! $profile) {
            return null;
        }

        /** @var EloquentCollection<int, AffiliateReferral> $referrals */
        $referrals = AffiliateReferral::with(['referredUser.vouchers'])
            ->where('affiliate_user_id', $user->id)
            ->latest('signed_up_at')
            ->get();

        $commissionRate = $this->commissionPerRedeemedVoucher();
        $clicks = (int) $profile->clicks;
        $signups = $referrals->count();
        $issuedVouchers = 0;
        $redeemedVouchers = 0;
        $issuedReferralCount = 0;
        $redeemedReferralCount = 0;
        $recentReferrals = [];

        foreach ($referrals as $index => $referral) {
            [$issued, $redeemed] = $this->countsForReferral($referral);
            $issuedVouchers += $issued;
            $redeemedVouchers += $redeemed;
            if ($issued > 0) {
                $issuedReferralCount++;
            }
            if ($redeemed > 0) {
                $redeemedReferralCount++;
            }

            if ($index < 6) {
                $recentReferrals[] = [
                    'id' => $referral->id,
                    'status' => $referral->status,
                    'signed_up_at' => optional($referral->signed_up_at)?->toIso8601String(),
                    'attributed_until' => optional($referral->attributed_until)?->toIso8601String(),
                    'first_voucher_issued_at' => optional($referral->first_voucher_issued_at)?->toIso8601String(),
                    'first_voucher_redeemed_at' => optional($referral->first_voucher_redeemed_at)?->toIso8601String(),
                    'issued_vouchers' => $issued,
                    'redeemed_vouchers' => $redeemed,
                    'user' => $referral->referredUser ? [
                        'id' => $referral->referredUser->id,
                        'name' => $referral->referredUser->name,
                        'email' => $referral->referredUser->email,
                    ] : null,
                ];
            }
        }

        $recentReferrals = collect($recentReferrals)->values();

        $activeAttributionSignups = $referrals->filter(function (AffiliateReferral $referral) {
            return ! $referral->attributed_until || $referral->attributed_until->isFuture();
        })->count();
        $recentSignupsLast7Days = $referrals->filter(function (AffiliateReferral $referral) {
            return $referral->signed_up_at && $referral->signed_up_at->greaterThanOrEqualTo(now()->subDays(7));
        })->count();

        return [
            'enabled' => true,
            'share_code' => $profile->share_code,
            'invite_url' => $this->inviteUrl($profile->share_code),
            'register_url' => $this->registerUrl($profile->share_code),
            'clicks' => $clicks,
            'signups' => $signups,
            'issued_vouchers' => $issuedVouchers,
            'redeemed_vouchers' => $redeemedVouchers,
            'commission_per_redeemed_voucher' => number_format($commissionRate, 2, '.', ''),
            'estimated_earnings' => number_format($redeemedVouchers * $commissionRate, 2, '.', ''),
            'attribution_days' => $this->attributionDays(),
            'active_attribution_signups' => $activeAttributionSignups,
            'recent_signups_last_7_days' => $recentSignupsLast7Days,
            'last_shared_at' => optional($profile->last_shared_at)?->toIso8601String(),
            'conversion_rates' => [
                'click_to_signup' => $this->percentage($signups, $clicks),
                'signup_to_issued' => $this->percentage($issuedReferralCount, $signups),
                'signup_to_redeemed' => $this->percentage($redeemedReferralCount, $signups),
            ],
            'recent_referrals' => $recentReferrals,
            'qr_image_url' => $this->qrImageUrl($profile->share_code),
            'share_message' => $this->shareMessage($profile->user?->name, $profile->share_code),
            'share_actions' => $this->shareActions($profile->share_code, $profile->user?->name),
        ];
    }

    public function invitePayload(AffiliateProfile $profile): array
    {
        return [
            'enabled' => true,
            'share_code' => $profile->share_code,
            'affiliate_name' => $profile->user?->name,
            'invite_url' => $this->inviteUrl($profile->share_code),
            'register_url' => $this->registerUrl($profile->share_code),
            'clicks' => (int) $profile->clicks,
            'attribution_days' => $this->attributionDays(),
            'qr_image_url' => $this->qrImageUrl($profile->share_code),
            'share_message' => $this->shareMessage($profile->user?->name, $profile->share_code),
            'share_actions' => $this->shareActions($profile->share_code, $profile->user?->name),
        ];
    }

    public function attributionDays(): int
    {
        return max(1, (int) config('talktocas.affiliates.attribution_days', 30));
    }

    public function commissionPerRedeemedVoucher(): float
    {
        return (float) config('talktocas.affiliates.commission_per_redeemed_voucher', 0);
    }



    private function qrImageUrl(string $shareCode): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . rawurlencode($this->inviteUrl($shareCode));
    }

    private function shareMessage(?string $affiliateName, string $shareCode): string
    {
        $name = trim((string) $affiliateName) !== '' ? trim((string) $affiliateName) : 'A TALK to CAS member';
        return sprintf(
            "%s invited you to TALK to CAS. Use my link to get started with Going Out, Food, and local deals: %s (code %s)",
            $name,
            $this->inviteUrl($shareCode),
            $shareCode
        );
    }

    private function shareActions(string $shareCode, ?string $affiliateName): array
    {
        $inviteUrl = $this->inviteUrl($shareCode);
        $message = $this->shareMessage($affiliateName, $shareCode);
        $subject = 'Join TALK to CAS through my invite';

        return [
            'whatsapp' => 'https://wa.me/?text=' . rawurlencode($message),
            'email' => 'mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($message),
            'x' => 'https://twitter.com/intent/tweet?text=' . rawurlencode($message),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($inviteUrl),
            'telegram' => 'https://t.me/share/url?url=' . rawurlencode($inviteUrl) . '&text=' . rawurlencode($message),
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($inviteUrl),
            'copy_value' => $inviteUrl,
            'copy_message' => $message,
        ];
    }


    private function percentage(int $value, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($value / $total) * 100, 1);
    }

    private function inviteUrl(string $shareCode): string
    {
        return rtrim((string) config('talktocas.affiliates.invite_base_url', rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/invite'), '/') . '/' . $shareCode;
    }

    private function registerUrl(string $shareCode): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/register?ref=' . urlencode($shareCode);
    }

    private function countsForReferral(AffiliateReferral $referral): array
    {
        $vouchers = $referral->referredUser?->vouchers ?? collect();
        if (! $vouchers instanceof Collection) {
            $vouchers = collect($vouchers);
        }

        $withinWindow = $vouchers->filter(function (Voucher $voucher) use ($referral) {
            $boundary = $referral->attributed_until;
            if (! $boundary instanceof CarbonInterface) {
                return true;
            }

            $issuedAt = $voucher->issued_at;
            return $issuedAt instanceof CarbonInterface ? $issuedAt->lessThanOrEqualTo($boundary) : true;
        });

        $issued = $withinWindow->count();
        $redeemed = $withinWindow->filter(function (Voucher $voucher) use ($referral) {
            if ($voucher->status !== 'redeemed') {
                return false;
            }

            $boundary = $referral->attributed_until;
            if (! $boundary instanceof CarbonInterface) {
                return true;
            }

            return ! ($voucher->redeemed_at instanceof CarbonInterface) || $voucher->redeemed_at->lessThanOrEqualTo($boundary);
        })->count();

        return [$issued, $redeemed];
    }

    private function markVoucherLifecycle(Voucher $voucher, string $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $voucher->loadMissing('user');
        $user = $voucher->user;
        if (! $user || ! $user->referred_by_user_id) {
            return;
        }

        $boundary = $user->referral_attributed_until;
        $eventAt = $event === 'redeemed' ? ($voucher->redeemed_at ?? now()) : ($voucher->issued_at ?? now());
        if ($boundary instanceof CarbonInterface && $eventAt instanceof CarbonInterface && $eventAt->greaterThan($boundary)) {
            return;
        }

        $referral = AffiliateReferral::where('referred_user_id', $user->id)
            ->where('affiliate_user_id', $user->referred_by_user_id)
            ->first();

        if (! $referral) {
            return;
        }

        if ($event === 'issued') {
            $referral->forceFill([
                'first_voucher_issued_at' => $referral->first_voucher_issued_at ?? $eventAt,
                'status' => in_array($referral->status, ['redeemed'], true) ? $referral->status : 'issued',
            ])->save();
            return;
        }

        $referral->forceFill([
            'first_voucher_issued_at' => $referral->first_voucher_issued_at ?? $voucher->issued_at ?? $eventAt,
            'first_voucher_redeemed_at' => $referral->first_voucher_redeemed_at ?? $eventAt,
            'status' => 'redeemed',
        ])->save();
    }

    private function generateUniqueShareCode(): string
    {
        do {
            $code = 'CAS' . strtoupper(Str::random(7));
        } while (AffiliateProfile::where('share_code', $code)->exists());

        return $code;
    }
}
