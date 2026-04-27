<?php

namespace App\Services\Tag;

use App\Models\TagRewardCredit;
use App\Models\VenueTag;
use App\Services\WhatsApp\ThreeSixtyDialogService;
use Illuminate\Support\Facades\Mail;

class TagNotificationService
{
    public function __construct(private readonly ThreeSixtyDialogService $threeSixtyDialogService)
    {
    }

    public function sendVenueInvite(VenueTag $tag): array
    {
        $metadata = $tag->metadata ?? [];
        $channels = [];
        $sentAt = now()->toIso8601String();

        $venueContactEmail = $metadata['venue_contact_email'] ?? null;
        $venueContactPhone = $metadata['venue_contact_phone'] ?? null;
        $inviterName = $tag->inviter_name ?: ($tag->referrer?->name ?: 'A TALK to CAS user');
        $rewardCredit = number_format((float) config('talktocas.tag_button.reward_credit_gbp', 5), 2, '.', '');

        if ($venueContactEmail) {
            Mail::raw(
                trim(sprintf(
                    "Hi there,\n\n%s has tagged %s on TALK to CAS.\n\nJoin now to offer free Uber ride and food voucher incentives to your customers.\n\nWhy join:\n- Only pay when customers convert\n- Control your offer values and times\n- Start with a tagged-venue flow already linked\n\nClaim your venue: %s\n\nWhen you join, the customer gets £%s reward credit.",
                    $inviterName,
                    $tag->venue_name,
                    $this->joinUrl($tag),
                    $rewardCredit
                )),
                function ($message) use ($venueContactEmail, $tag) {
                    $message->to($venueContactEmail)->subject('Your customers want ' . $tag->venue_name . ' on TALK to CAS');
                }
            );

            $channels[] = 'email';
        }

        if ($venueContactPhone) {
            $this->threeSixtyDialogService->sendText(
                $venueContactPhone,
                trim(sprintf(
                    "%s has tagged %s on TALK to CAS. Join now to activate customer voucher offers. Claim: %s",
                    $inviterName,
                    $tag->venue_name,
                    $this->joinUrl($tag)
                ))
            );

            $channels[] = 'whatsapp';
        }

        $metadata['invite_notifications'] = [
            'channels' => $channels,
            'last_sent_at' => $channels ? $sentAt : ($metadata['invite_notifications']['last_sent_at'] ?? null),
            'email_sent' => in_array('email', $channels, true),
            'whatsapp_sent' => in_array('whatsapp', $channels, true),
            'delivery_attempted' => true,
        ];

        $tag->forceFill(['metadata' => $metadata])->save();

        return $metadata['invite_notifications'];
    }

    public function sendRewardEarned(VenueTag $tag, TagRewardCredit $rewardCredit): array
    {
        $tag->loadMissing('referrer');
        $user = $tag->referrer;
        if (! $user) {
            return ['channels' => []];
        }

        $channels = [];
        $amount = number_format((float) $rewardCredit->amount, 2, '.', '');

        if ($user->email) {
            Mail::raw(
                trim(sprintf(
                    "Good news! %s joined TALK to CAS from your tag invite.\n\nReward earned: £%s\nVenue: %s\nTag code: %s\n\nYour reward has been logged in the dashboard for follow-up.",
                    $tag->venue_name,
                    $amount,
                    $tag->venue_name,
                    $tag->share_code
                )),
                function ($message) use ($user) {
                    $message->to($user->email)->subject('TALK to CAS tag reward earned');
                }
            );

            $channels[] = 'email';
        }

        if ($user->phone) {
            $this->threeSixtyDialogService->sendText(
                $user->phone,
                trim(sprintf(
                    "Your tagged venue %s joined TALK to CAS. Reward earned: £%s. Check your dashboard for the updated status.",
                    $tag->venue_name,
                    $amount
                ))
            );

            $channels[] = 'whatsapp';
        }

        $rewardCredit->forceFill([
            'status' => $channels ? 'notified' : $rewardCredit->status,
            'notified_at' => $channels ? now() : $rewardCredit->notified_at,
            'metadata' => array_merge($rewardCredit->metadata ?? [], [
                'notification_channels' => $channels,
            ]),
        ])->save();

        return ['channels' => $channels];
    }

    private function joinUrl(VenueTag $tag): string
    {
        return rtrim((string) config('app.url'), '/') . '/join?tag=' . urlencode($tag->share_code);
    }
}
