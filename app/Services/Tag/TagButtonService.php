<?php

namespace App\Services\Tag;

use App\Models\Merchant;
use App\Models\TagRewardCredit;
use App\Models\User;
use App\Models\VenueTag;
use App\Models\WhatsAppSession;
use Illuminate\Support\Str;

class TagButtonService
{
    public function __construct(private readonly TagNotificationService $tagNotificationService)
    {
    }

    public function createFromSession(WhatsAppSession $session, string $venueName): VenueTag
    {
        return $this->create([
            'venue_name' => $venueName,
            'referrer_user_id' => $session->user_id,
            'session_id' => $session->id,
            'inviter_name' => $session->user?->name,
            'inviter_phone' => $session->user?->phone,
            'inviter_email' => $session->user?->email,
            'source_channel' => $session->channel ?: 'whatsapp',
            'metadata' => [
                'journey_type' => $session->journey_type,
                'postcode' => $session->postcode,
                'created_from' => 'whatsapp_session',
            ],
        ]);
    }

    public function create(array $payload): VenueTag
    {
        $venueName = $this->normalizeVenueName((string) ($payload['venue_name'] ?? ''));
        $shareCode = $this->generateShareCode();
        $expiresDays = max(1, (int) config('talktocas.tag_button.invite_expires_days', 7));
        $metadata = $payload['metadata'] ?? [];

        if (! empty($payload['venue_contact_email'])) {
            $metadata['venue_contact_email'] = trim((string) $payload['venue_contact_email']);
        }

        if (! empty($payload['venue_contact_phone'])) {
            $metadata['venue_contact_phone'] = trim((string) $payload['venue_contact_phone']);
        }

        $tag = VenueTag::create([
            'referrer_user_id' => $payload['referrer_user_id'] ?? null,
            'session_id' => $payload['session_id'] ?? null,
            'invited_merchant_id' => $payload['invited_merchant_id'] ?? null,
            'venue_name' => $venueName,
            'normalized_name' => $this->normalizeVenueName($venueName),
            'share_code' => $shareCode,
            'inviter_name' => $payload['inviter_name'] ?? null,
            'inviter_phone' => $payload['inviter_phone'] ?? null,
            'inviter_email' => $payload['inviter_email'] ?? null,
            'source_channel' => $payload['source_channel'] ?? 'website',
            'status' => 'pending',
            'expires_at' => now()->addDays($expiresDays),
            'metadata' => $metadata,
        ]);

        if ((bool) ($payload['send_notifications'] ?? true)) {
            $this->tagNotificationService->sendVenueInvite($tag->fresh(['referrer', 'invitedMerchant', 'rewardCredit']));
        }

        return $tag->fresh(['referrer:id,name', 'invitedMerchant:id,business_name', 'rewardCredit']);
    }

    public function resendInvite(string $shareCode): ?VenueTag
    {
        $tag = $this->findPublic($shareCode);
        if (! $tag || $tag->status === 'expired') {
            return $tag;
        }

        $this->tagNotificationService->sendVenueInvite($tag);

        return $tag->fresh(['referrer:id,name', 'invitedMerchant:id,business_name', 'rewardCredit']);
    }

    public function findPublic(string $shareCode): ?VenueTag
    {
        $tag = VenueTag::query()
            ->with(['referrer:id,name', 'invitedMerchant:id,business_name', 'rewardCredit'])
            ->where('share_code', strtoupper(trim($shareCode)))
            ->first();

        if (! $tag) {
            return null;
        }

        return $this->syncStatus($tag);
    }

    public function markMerchantJoined(string $shareCode, Merchant $merchant): ?VenueTag
    {
        $tag = VenueTag::query()
            ->with(['referrer', 'rewardCredit'])
            ->where('share_code', strtoupper(trim($shareCode)))
            ->first();

        if (! $tag) {
            return null;
        }

        $tag = $this->syncStatus($tag);

        if ($tag->status === 'expired') {
            return $tag;
        }

        $metadata = $tag->metadata ?? [];
        $metadata['joined_business_name'] = $merchant->business_name;
        $metadata['joined_at_registration'] = now()->toIso8601String();

        $tag->update([
            'invited_merchant_id' => $merchant->id,
            'status' => 'joined',
            'joined_at' => now(),
            'metadata' => $metadata,
        ]);

        $tag->refresh()->loadMissing(['referrer', 'invitedMerchant', 'rewardCredit']);
        $rewardCredit = $this->createRewardCreditIfEligible($tag);

        if ($rewardCredit && ! $rewardCredit->notified_at) {
            $this->tagNotificationService->sendRewardEarned($tag, $rewardCredit);
        }

        return $tag->fresh(['referrer:id,name', 'invitedMerchant:id,business_name', 'rewardCredit']);
    }

    public function dashboardForUser(User $user): ?array
    {
        if (! $user->isUser()) {
            return null;
        }

        $tags = VenueTag::with(['invitedMerchant:id,business_name', 'rewardCredit'])
            ->where('referrer_user_id', $user->id)
            ->latest()
            ->get();

        $rewardCredits = TagRewardCredit::with('venueTag:id,share_code,venue_name')
            ->where('user_id', $user->id)
            ->latest('awarded_at')
            ->get();

        return [
            'enabled' => (bool) config('talktocas.tag_button.enabled', true),
            'reward_credit_amount' => number_format((float) config('talktocas.tag_button.reward_credit_gbp', 5), 2, '.', ''),
            'summary' => [
                'total_tags' => $tags->count(),
                'joined_tags' => $tags->where('status', 'joined')->count(),
                'pending_rewards' => $tags->where('status', 'joined')->filter(fn (VenueTag $tag) => ! $tag->rewardCredit)->count(),
                'earned_rewards' => $rewardCredits->count(),
                'earned_credit_total' => number_format((float) $rewardCredits->sum('amount'), 2, '.', ''),
            ],
            'recent_tags' => $tags->take(6)->values()->map(fn (VenueTag $tag) => $this->toDashboardPayload($tag))->all(),
            'recent_reward_credits' => $rewardCredits->take(6)->values()->map(function (TagRewardCredit $credit) {
                return [
                    'id' => $credit->id,
                    'amount' => number_format((float) $credit->amount, 2, '.', ''),
                    'status' => $credit->status,
                    'awarded_at' => $credit->awarded_at?->toIso8601String(),
                    'notified_at' => $credit->notified_at?->toIso8601String(),
                    'venue_tag' => $credit->venueTag ? [
                        'id' => $credit->venueTag->id,
                        'share_code' => $credit->venueTag->share_code,
                        'venue_name' => $credit->venueTag->venue_name,
                    ] : null,
                ];
            })->all(),
        ];
    }

    public function toPublicPayload(VenueTag $tag): array
    {
        $tag = $this->syncStatus($tag);
        $isExpired = $tag->status === 'expired';
        $metadata = $tag->metadata ?? [];
        $inviteNotifications = $metadata['invite_notifications'] ?? [];
        $reward = $tag->rewardCredit;
        $shareMessage = $this->shareMessage($tag);
        $shareActions = $this->shareActions($tag, $shareMessage);

        return [
            'id' => $tag->id,
            'share_code' => $tag->share_code,
            'venue_name' => $tag->venue_name,
            'status' => $tag->status,
            'reward_credit' => number_format((float) config('talktocas.tag_button.reward_credit_gbp', 5), 2, '.', ''),
            'reward_status' => $reward?->status ?? ($tag->status === 'joined' ? 'earned' : 'pending'),
            'reward_awarded_at' => $reward?->awarded_at?->toIso8601String(),
            'inviter_name' => $tag->inviter_name,
            'inviter_display_name' => $tag->inviter_name ?: $tag->referrer?->name,
            'source_channel' => $tag->source_channel,
            'public_url' => $this->publicUrl($tag),
            'join_url' => $this->joinUrl($tag),
            'share_message' => $shareMessage,
            'share_actions' => $shareActions,
            'share_copy_variants' => [
                'instagram' => $shareMessage,
                'tiktok' => $shareMessage,
                'copy_link' => $this->publicUrl($tag),
            ],
            'expires_at' => $tag->expires_at?->toIso8601String(),
            'is_expired' => $isExpired,
            'joined_at' => $tag->joined_at?->toIso8601String(),
            'joined_merchant' => $tag->invitedMerchant ? [
                'id' => $tag->invitedMerchant->id,
                'business_name' => $tag->invitedMerchant->business_name,
            ] : null,
            'venue_contact_email' => $metadata['venue_contact_email'] ?? null,
            'venue_contact_phone' => $metadata['venue_contact_phone'] ?? null,
            'invite_notifications' => [
                'channels' => array_values($inviteNotifications['channels'] ?? []),
                'last_sent_at' => $inviteNotifications['last_sent_at'] ?? null,
                'email_sent' => (bool) ($inviteNotifications['email_sent'] ?? false),
                'whatsapp_sent' => (bool) ($inviteNotifications['whatsapp_sent'] ?? false),
                'delivery_attempted' => (bool) ($inviteNotifications['delivery_attempted'] ?? false),
            ],
        ];
    }

    public function publicUrl(VenueTag $tag): string
    {
        return rtrim((string) config('talktocas.tag_button.base_url'), '/') . '/' . $tag->share_code;
    }

    public function joinUrl(VenueTag $tag): string
    {
        return rtrim((string) config('app.url'), '/') . '/join?tag=' . urlencode($tag->share_code);
    }

    private function createRewardCreditIfEligible(VenueTag $tag): ?TagRewardCredit
    {
        if (! $tag->referrer_user_id) {
            return null;
        }

        return TagRewardCredit::firstOrCreate(
            ['venue_tag_id' => $tag->id],
            [
                'user_id' => $tag->referrer_user_id,
                'amount' => (float) config('talktocas.tag_button.reward_credit_gbp', 5),
                'status' => 'earned',
                'awarded_at' => now(),
                'metadata' => [
                    'share_code' => $tag->share_code,
                    'venue_name' => $tag->venue_name,
                ],
            ]
        );
    }

    private function toDashboardPayload(VenueTag $tag): array
    {
        $shareMessage = $this->shareMessage($tag);

        return [
            'id' => $tag->id,
            'share_code' => $tag->share_code,
            'venue_name' => $tag->venue_name,
            'status' => $tag->status,
            'reward_credit' => number_format((float) config('talktocas.tag_button.reward_credit_gbp', 5), 2, '.', ''),
            'reward_status' => $tag->rewardCredit?->status ?? ($tag->status === 'joined' ? 'earned' : 'pending'),
            'joined_at' => $tag->joined_at?->toIso8601String(),
            'created_at' => $tag->created_at?->toIso8601String(),
            'public_url' => $this->publicUrl($tag),
            'join_url' => $this->joinUrl($tag),
            'share_message' => $shareMessage,
            'share_actions' => $this->shareActions($tag, $shareMessage),
            'joined_merchant' => $tag->invitedMerchant ? [
                'id' => $tag->invitedMerchant->id,
                'business_name' => $tag->invitedMerchant->business_name,
            ] : null,
        ];
    }

    private function syncStatus(VenueTag $tag): VenueTag
    {
        if ($tag->status === 'pending' && $tag->expires_at && $tag->expires_at->isPast()) {
            $tag->update(['status' => 'expired']);
            $tag->refresh();
        }

        return $tag;
    }

    private function generateShareCode(): string
    {
        do {
            $shareCode = strtoupper(Str::random(8));
        } while (VenueTag::query()->where('share_code', $shareCode)->exists());

        return $shareCode;
    }

    private function normalizeVenueName(string $venueName): string
    {
        return trim(preg_replace('/\s+/', ' ', $venueName));
    }

    private function shareMessage(VenueTag $tag): string
    {
        $inviter = $tag->inviter_name ?: $tag->referrer?->name ?: 'A TALK to CAS user';
        $reward = number_format((float) config('talktocas.tag_button.reward_credit_gbp', 5), 2, '.', '');

        return sprintf(
            "%s tagged %s on TALK to CAS. Join now to offer your customers free Uber deals. When you sign up, the referrer gets £%s reward credit. %s",
            $inviter,
            $tag->venue_name,
            $reward,
            $this->publicUrl($tag)
        );
    }

    private function shareActions(VenueTag $tag, string $shareMessage): array
    {
        $publicUrl = $this->publicUrl($tag);
        $joinUrl = $this->joinUrl($tag);
        $encodedMessage = rawurlencode($shareMessage);
        $encodedPublicUrl = rawurlencode($publicUrl);
        $encodedJoinUrl = rawurlencode($joinUrl);
        $emailSubject = rawurlencode('Your customers tagged ' . $tag->venue_name . ' on TALK to CAS');
        $emailBody = rawurlencode($shareMessage . "\n\nClaim your venue: " . $joinUrl);

        return [
            'whatsapp' => 'https://wa.me/?text=' . $encodedMessage,
            'email' => 'mailto:?subject=' . $emailSubject . '&body=' . $emailBody,
            'x' => 'https://twitter.com/intent/tweet?text=' . $encodedMessage,
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $encodedPublicUrl,
            'telegram' => 'https://t.me/share/url?url=' . $encodedPublicUrl . '&text=' . $encodedMessage,
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encodedJoinUrl,
        ];
    }
}
