<?php

namespace App\Services\Fraud;

use App\Models\FraudSignal;
use App\Models\User;
use App\Models\Venue;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FraudPreventionService
{
    public function isEnabled(): bool
    {
        return (bool) config('talktocas.fraud.enabled', true);
    }

    public function currentFingerprint(?string $explicit = null): ?string
    {
        $value = $explicit ?: request()->header('X-Device-Fingerprint');
        $value = is_string($value) ? trim($value) : null;

        return filled($value) ? substr($value, 0, 128) : null;
    }

    public function guardVoucherIssuance(User $user, Venue $venue, ?string $deviceFingerprint = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $deviceFingerprint = $this->currentFingerprint($deviceFingerprint);
        $this->rememberFingerprint($user, $deviceFingerprint);

        if ($this->isBlocked($user)) {
            $this->logSignal($user, null, 'blocked_user_attempt', 'high', 15, 'blocked', 'Blocked user attempted to request another voucher.', [
                'venue_id' => $venue->id,
                'device_fingerprint' => $deviceFingerprint,
                'blocked_until' => optional($user->fraud_blocked_until)?->toIso8601String(),
            ]);

            throw ValidationException::withMessages([
                'fraud' => [$this->blockedMessage($user)],
            ]);
        }

        if ($user->phone_verified_at && filled($user->phone) && $this->hasRecentVoucherForPhone($user)) {
            $this->flagAndEscalate($user, 'voucher_limit_30d', 'high', 60, 'One voucher per verified phone number within 30 days rule triggered.', [
                'phone' => $user->phone,
                'venue_id' => $venue->id,
            ]);

            throw ValidationException::withMessages([
                'fraud' => ['This account has already received a voucher within the last 30 days for the verified phone number on file.'],
            ]);
        }

        if ($deviceFingerprint && $this->sharedFingerprintUsers($user, $deviceFingerprint) > 0) {
            $this->flagAndEscalate($user, 'shared_device_fingerprint', 'medium', 25, 'Same device fingerprint has been used by another account.', [
                'venue_id' => $venue->id,
                'device_fingerprint' => $deviceFingerprint,
                'other_users_count' => $this->sharedFingerprintUsers($user, $deviceFingerprint),
            ]);
        }

        if (! $user->phone_verified_at && $this->recentVoucherAttempts($user, 24) >= 2) {
            $this->flagAndEscalate($user, 'rapid_repeat_attempts', 'medium', 20, 'Multiple voucher requests without verified phone within 24 hours.', [
                'venue_id' => $venue->id,
                'attempt_count_24h' => $this->recentVoucherAttempts($user, 24),
            ]);
        }
    }

    public function afterVoucherIssued(Voucher $voucher, ?string $deviceFingerprint = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $deviceFingerprint = $this->currentFingerprint($deviceFingerprint);
        $this->rememberFingerprint($voucher->user, $deviceFingerprint);

        FraudSignal::create([
            'user_id' => $voucher->user_id,
            'voucher_id' => $voucher->id,
            'signal_type' => 'voucher_issued',
            'severity' => 'low',
            'score_delta' => 0,
            'status' => 'logged',
            'reason' => 'Voucher issued after fraud checks passed.',
            'context' => [
                'code' => $voucher->code,
                'venue_id' => $voucher->venue_id,
                'merchant_id' => $voucher->merchant_id,
                'device_fingerprint' => $deviceFingerprint,
            ],
            'triggered_at' => now(),
        ]);
    }

    public function dashboardSummary(): array
    {
        return [
            'flagged_users' => User::query()->where('fraud_score', '>', 0)->count(),
            'blocked_users' => User::query()->where('fraud_status', 'blocked')->count(),
            'pending_review_users' => User::query()->where('fraud_status', 'review')->count(),
            'signals_last_7_days' => FraudSignal::query()->where('triggered_at', '>=', now()->subDays(7))->count(),
        ];
    }

    public function flaggedUsers(): Collection
    {
        return User::query()
            ->with(['merchant', 'fraudSignals' => fn ($query) => $query->latest()->take(5)])
            ->where(function ($query) {
                $query->where('fraud_score', '>', 0)
                    ->orWhereIn('fraud_status', ['review', 'blocked']);
            })
            ->orderByDesc('fraud_score')
            ->orderByDesc('updated_at')
            ->get();
    }

    public function userPayload(User $user): array
    {
        $recentSignals = $user->fraudSignals->sortByDesc('triggered_at')->take(5)->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->primaryRole(),
            'fraud_score' => (int) ($user->fraud_score ?? 0),
            'fraud_status' => $user->fraud_status ?? 'clear',
            'fraud_blocked_until' => optional($user->fraud_blocked_until)?->toIso8601String(),
            'last_device_fingerprint' => $user->last_device_fingerprint,
            'last_fraud_review_at' => optional($user->last_fraud_review_at)?->toIso8601String(),
            'voucher_count' => $user->vouchers()->count(),
            'recent_signal_count' => $user->fraudSignals()->where('triggered_at', '>=', now()->subDays(7))->count(),
            'merchant' => $user->merchant ? [
                'id' => $user->merchant->id,
                'business_name' => $user->merchant->business_name,
            ] : null,
            'signals' => $recentSignals->map(fn (FraudSignal $signal) => [
                'id' => $signal->id,
                'signal_type' => $signal->signal_type,
                'severity' => $signal->severity,
                'score_delta' => (int) $signal->score_delta,
                'status' => $signal->status,
                'reason' => $signal->reason,
                'review_notes' => $signal->review_notes,
                'triggered_at' => optional($signal->triggered_at)->toIso8601String(),
                'reviewed_at' => optional($signal->reviewed_at)->toIso8601String(),
                'context' => $signal->context ?? [],
            ])->values(),
        ];
    }

    public function statusForUser(User $user): array
    {
        return [
            'score' => (int) ($user->fraud_score ?? 0),
            'status' => $user->fraud_status ?? 'clear',
            'blocked_until' => optional($user->fraud_blocked_until)?->toIso8601String(),
            'phone_rule_window_days' => (int) config('talktocas.fraud.phone_rule_window_days', 30),
            'last_device_fingerprint' => $user->last_device_fingerprint,
            'recent_signals' => $user->fraudSignals()->where('triggered_at', '>=', now()->subDays(7))->count(),
            'message' => match ($user->fraud_status) {
                'blocked' => $this->blockedMessage($user),
                'review' => 'This account is flagged for manual fraud review.',
                default => 'Fraud screening is active on this account.',
            },
        ];
    }

    public function markReviewed(User $user, ?string $notes = null): User
    {
        $user->update([
            'fraud_status' => ($user->fraud_score ?? 0) > 0 ? 'reviewed' : 'clear',
            'last_fraud_review_at' => now(),
            'fraud_blocked_until' => null,
        ]);

        $user->fraudSignals()->whereIn('status', ['open', 'blocked'])->update([
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        return $user->fresh(['fraudSignals', 'merchant']);
    }

    public function blockUser(User $user, ?string $reason = null, ?int $days = null): User
    {
        $days = $days ?: (int) config('talktocas.fraud.default_manual_block_days', 30);

        $user->update([
            'fraud_status' => 'blocked',
            'fraud_blocked_until' => $days > 0 ? now()->addDays($days) : null,
            'last_fraud_review_at' => now(),
        ]);

        $this->logSignal($user, null, 'manual_admin_block', 'high', 0, 'blocked', $reason ?: 'Blocked by admin review.', [
            'manual_block_days' => $days,
        ]);

        return $user->fresh(['fraudSignals', 'merchant']);
    }

    public function unblockUser(User $user, ?string $notes = null): User
    {
        $user->update([
            'fraud_status' => ($user->fraud_score ?? 0) > 0 ? 'reviewed' : 'clear',
            'fraud_blocked_until' => null,
            'last_fraud_review_at' => now(),
        ]);

        $user->fraudSignals()->where('status', 'blocked')->update([
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'review_notes' => $notes ?: 'User manually unblocked.',
        ]);

        return $user->fresh(['fraudSignals', 'merchant']);
    }

    public function recordProviderIncident(Voucher $voucher, string $incidentType, string $reason, int $scoreDelta, string $severity = 'medium', array $context = []): FraudSignal
    {
        if (! $this->isEnabled()) {
            return FraudSignal::create([
                'user_id' => $voucher->user_id,
                'voucher_id' => $voucher->id,
                'signal_type' => $incidentType,
                'severity' => $severity,
                'score_delta' => 0,
                'status' => 'logged',
                'reason' => $reason,
                'context' => array_merge([
                    'voucher_id' => $voucher->id,
                    'merchant_id' => $voucher->merchant_id,
                    'venue_id' => $voucher->venue_id,
                ], $context),
                'triggered_at' => now(),
            ]);
        }

        return DB::transaction(function () use ($voucher, $incidentType, $reason, $scoreDelta, $severity, $context) {
            $signal = FraudSignal::create([
                'user_id' => $voucher->user_id,
                'voucher_id' => $voucher->id,
                'signal_type' => $incidentType,
                'severity' => $severity,
                'score_delta' => $scoreDelta,
                'status' => 'open',
                'reason' => $reason,
                'context' => array_merge([
                    'voucher_id' => $voucher->id,
                    'merchant_id' => $voucher->merchant_id,
                    'venue_id' => $voucher->venue_id,
                ], $context),
                'triggered_at' => now(),
            ]);

            $user = $voucher->user()->firstOrFail();
            $newScore = max(0, (int) ($user->fraud_score ?? 0) + $scoreDelta);
            $reviewThreshold = (int) config('talktocas.fraud.review_threshold', 25);
            $blockThreshold = (int) config('talktocas.fraud.auto_block_threshold', 75);
            $status = $newScore >= $blockThreshold ? 'blocked' : ($newScore >= $reviewThreshold ? 'review' : ($user->fraud_status ?: 'clear'));

            $user->update([
                'fraud_score' => $newScore,
                'fraud_status' => $status,
                'fraud_blocked_until' => $newScore >= $blockThreshold
                    ? now()->addDays((int) config('talktocas.fraud.default_auto_block_days', 30))
                    : $user->fraud_blocked_until,
            ]);

            if ($status === 'blocked') {
                $signal->update([
                    'status' => 'blocked',
                ]);
            }

            return $signal;
        });
    }

    private function hasRecentVoucherForPhone(User $user): bool
    {
        return Voucher::query()
            ->whereHas('user', fn ($query) => $query->where('phone', $user->phone))
            ->whereIn('status', ['issued', 'redeemed'])
            ->where('issued_at', '>=', now()->subDays((int) config('talktocas.fraud.phone_rule_window_days', 30)))
            ->exists();
    }

    private function recentVoucherAttempts(User $user, int $hours): int
    {
        return Voucher::query()
            ->where('user_id', $user->id)
            ->where('issued_at', '>=', now()->subHours($hours))
            ->count();
    }

    private function sharedFingerprintUsers(User $user, string $deviceFingerprint): int
    {
        return User::query()
            ->where('id', '!=', $user->id)
            ->where('last_device_fingerprint', $deviceFingerprint)
            ->count();
    }

    private function rememberFingerprint(User $user, ?string $deviceFingerprint): void
    {
        if (! $deviceFingerprint || $user->last_device_fingerprint === $deviceFingerprint) {
            return;
        }

        $user->forceFill([
            'last_device_fingerprint' => $deviceFingerprint,
        ])->save();
    }

    private function isBlocked(User $user): bool
    {
        if (($user->fraud_status ?? 'clear') !== 'blocked') {
            return false;
        }

        if ($user->fraud_blocked_until === null) {
            return true;
        }

        if ($user->fraud_blocked_until->isFuture()) {
            return true;
        }

        $user->forceFill([
            'fraud_status' => ($user->fraud_score ?? 0) > 0 ? 'reviewed' : 'clear',
            'fraud_blocked_until' => null,
        ])->save();

        return false;
    }

    private function blockedMessage(User $user): string
    {
        if ($user->fraud_blocked_until) {
            return 'This account is temporarily blocked from receiving new vouchers until ' . $user->fraud_blocked_until->format('d M Y H:i') . '.';
        }

        return 'This account is currently blocked from receiving new vouchers pending manual review.';
    }

    private function flagAndEscalate(User $user, string $type, string $severity, int $scoreDelta, string $reason, array $context = []): FraudSignal
    {
        return DB::transaction(function () use ($user, $type, $severity, $scoreDelta, $reason, $context) {
            $signal = $this->logSignal($user, null, $type, $severity, $scoreDelta, 'open', $reason, $context);

            $newScore = max(0, (int) ($user->fraud_score ?? 0) + $scoreDelta);
            $reviewThreshold = (int) config('talktocas.fraud.review_threshold', 25);
            $blockThreshold = (int) config('talktocas.fraud.auto_block_threshold', 75);
            $status = $newScore >= $blockThreshold ? 'blocked' : ($newScore >= $reviewThreshold ? 'review' : ($user->fraud_status ?: 'clear'));

            $user->update([
                'fraud_score' => $newScore,
                'fraud_status' => $status,
                'fraud_blocked_until' => $newScore >= $blockThreshold
                    ? now()->addDays((int) config('talktocas.fraud.default_auto_block_days', 30))
                    : $user->fraud_blocked_until,
            ]);

            if ($status === 'blocked') {
                $signal->update([
                    'status' => 'blocked',
                ]);
            }

            return $signal;
        });
    }

    private function logSignal(User $user, ?Voucher $voucher, string $type, string $severity, int $scoreDelta, string $status, string $reason, array $context = []): FraudSignal
    {
        return FraudSignal::create([
            'user_id' => $user->id,
            'voucher_id' => $voucher?->id,
            'signal_type' => $type,
            'severity' => $severity,
            'score_delta' => $scoreDelta,
            'status' => $status,
            'reason' => $reason,
            'context' => array_filter($context, fn ($value) => ! is_null($value) && $value !== ''),
            'triggered_at' => now(),
        ]);
    }
}
