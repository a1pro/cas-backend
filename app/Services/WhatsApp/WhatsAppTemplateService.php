<?php

namespace App\Services\WhatsApp;

use App\Models\CasMessageTemplate;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class WhatsAppTemplateService
{
    public function __construct(private readonly ThreeSixtyDialogService $threeSixtyDialogService)
    {
    }

    public function providerStatus(): array
    {
        $connectUrl = $this->threeSixtyDialogService->startLink();
        $allTemplates = CasMessageTemplate::query()->count();
        $approvedTemplates = CasMessageTemplate::query()->where('approval_status', 'approved')->count();
        $pendingTemplates = CasMessageTemplate::query()->where('approval_status', 'pending_approval')->count();

        return [
            'provider' => (string) config('talktocas.whatsapp.provider', '360dialog'),
            'enabled' => $this->threeSixtyDialogService->enabled(),
            'display_phone_number' => $this->threeSixtyDialogService->displayPhoneNumber(),
            'connect_url' => $connectUrl,
            'configuration' => $this->threeSixtyDialogService->configurationStatus(),
            'templates_required' => (bool) config('talktocas.whatsapp.templates_required', true),
            'allow_simulated_approval' => (bool) config('talktocas.whatsapp.allow_simulated_approval', true),
            'counts' => [
                'total' => $allTemplates,
                'approved' => $approvedTemplates,
                'pending_approval' => $pendingTemplates,
                'approval_completion_rate' => $allTemplates > 0 ? round(($approvedTemplates / $allTemplates) * 100, 1) : 0,
            ],
            'phase_message' => $this->buildPhaseMessage($approvedTemplates, $pendingTemplates),
        ];
    }

    public function dashboardPayload(): array
    {
        $templates = CasMessageTemplate::query()
            ->orderBy('channel')
            ->orderBy('journey_type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'provider' => $this->providerStatus(),
            'summary' => [
                'total_templates' => $templates->count(),
                'approved_templates' => $templates->where('approval_status', 'approved')->count(),
                'pending_templates' => $templates->where('approval_status', 'pending_approval')->count(),
                'rejected_templates' => $templates->where('approval_status', 'rejected')->count(),
                'draft_templates' => $templates->where('approval_status', 'draft')->count(),
                'active_templates' => $templates->where('is_active', true)->count(),
            ],
            'templates' => $templates->map(fn (CasMessageTemplate $template) => $this->transformTemplate($template))->values(),
            'approved_template_keys' => $templates
                ->where('approval_status', 'approved')
                ->where('is_active', true)
                ->map(fn (CasMessageTemplate $template) => $template->key)
                ->unique()
                ->values()
                ->all(),
            'starter_templates' => $this->starterPackPresets(),
        ];
    }

    public function create(array $attributes): CasMessageTemplate
    {
        $attributes = $this->normalizeAttributes($attributes, true);

        return CasMessageTemplate::query()->create($attributes);
    }

    public function update(CasMessageTemplate $template, array $attributes): CasMessageTemplate
    {
        $approvalStatus = $template->approval_status;

        if (($attributes['body'] ?? null) !== null && trim((string) $attributes['body']) !== trim((string) $template->body)) {
            if ($template->approval_status === 'approved') {
                $approvalStatus = 'draft';
            }
        }

        $normalized = $this->normalizeAttributes($attributes, false);
        if (! array_key_exists('approval_status', $normalized)) {
            $normalized['approval_status'] = $approvalStatus;
        }

        $template->fill($normalized);
        $template->save();

        return $template->refresh();
    }

    public function submitForApproval(CasMessageTemplate $template): CasMessageTemplate
    {
        if (trim((string) $template->body) === '') {
            throw ValidationException::withMessages([
                'body' => ['Template body cannot be empty before submission.'],
            ]);
        }

        $template->forceFill([
            'approval_status' => 'pending_approval',
            'last_submitted_at' => now(),
            'last_synced_at' => now(),
            'provider_template_id' => $template->provider_template_id ?: $this->makeProviderTemplateId($template),
            'provider_template_name' => $template->provider_template_name ?: $this->makeProviderTemplateName($template),
            'approval_notes' => null,
        ])->save();

        return $template->refresh();
    }

    public function simulateApproval(CasMessageTemplate $template, string $status, ?string $notes = null): CasMessageTemplate
    {
        if (! (bool) config('talktocas.whatsapp.allow_simulated_approval', true)) {
            throw ValidationException::withMessages([
                'approval_status' => ['Simulated approval is disabled in configuration.'],
            ]);
        }

        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'approval_status' => ['Simulated status must be approved or rejected.'],
            ]);
        }

        $template->forceFill([
            'approval_status' => $status,
            'approval_notes' => $notes,
            'last_synced_at' => now(),
            'provider_template_id' => $template->provider_template_id ?: $this->makeProviderTemplateId($template),
            'provider_template_name' => $template->provider_template_name ?: $this->makeProviderTemplateName($template),
        ])->save();

        return $template->refresh();
    }

    public function approvedTemplatePreview(): array
    {
        return CasMessageTemplate::query()
            ->where('approval_status', 'approved')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(6)
            ->get()
            ->map(fn (CasMessageTemplate $template) => [
                'id' => $template->id,
                'key' => $template->key,
                'channel' => $template->channel,
                'journey_type' => $template->journey_type,
                'body' => $template->body,
            ])
            ->values()
            ->all();
    }

    public function installStarterPack(bool $overwriteExisting = false): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $templateIds = [];

        foreach ($this->starterPackPresets() as $preset) {
            $scope = [
                'key' => $preset['key'],
                'channel' => $preset['channel'] ?? 'whatsapp',
                'journey_type' => $preset['journey_type'] ?? null,
                'weather_condition' => $preset['weather_condition'] ?? null,
            ];

            /** @var CasMessageTemplate|null $existing */
            $existing = CasMessageTemplate::query()->where($scope)->first();

            if (! $existing) {
                $template = $this->create($preset);
                $created++;
                $templateIds[] = $template->id;
                continue;
            }

            if (! $overwriteExisting) {
                $skipped++;
                $templateIds[] = $existing->id;
                continue;
            }

            $template = $this->update($existing, Arr::only($preset, [
                'key',
                'channel',
                'journey_type',
                'weather_condition',
                'emoji',
                'body',
                'is_active',
                'sort_order',
                'category',
                'language',
            ]));

            $updated++;
            $templateIds[] = $template->id;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'overwrite_existing' => $overwriteExisting,
            'template_ids' => $templateIds,
        ];
    }

    public function starterPackPresets(): array
    {
        return [
            [
                'key' => 'voucher_delivery_ride',
                'channel' => 'whatsapp',
                'journey_type' => 'going_out',
                'emoji' => '🚕',
                'body' => "Your TALK to CAS ride voucher is ready. Use your exact provider link below to unlock the venue offer in Uber. If the ride is marked as 2 trips, it covers both the trip there and the return journey.",
                'is_active' => true,
                'sort_order' => 10,
                'category' => 'utility',
                'language' => 'en_GB',
            ],
            [
                'key' => 'voucher_delivery_food',
                'channel' => 'whatsapp',
                'journey_type' => 'ordering_food',
                'emoji' => '🍔',
                'body' => "Your TALK to CAS food voucher is ready. Tap the exact provider link below to load the offer in Uber Eats. Check the minimum spend before ordering so the discount applies correctly.",
                'is_active' => true,
                'sort_order' => 20,
                'category' => 'utility',
                'language' => 'en_GB',
            ],
            [
                'key' => 'merchant_wallet_low_balance',
                'channel' => 'whatsapp',
                'journey_type' => 'merchant_alert',
                'emoji' => '⚠️',
                'body' => "TALK to CAS wallet alert: your balance is running low. Top up now so your ride and food offers stay live for nearby users.",
                'is_active' => true,
                'sort_order' => 30,
                'category' => 'utility',
                'language' => 'en_GB',
            ],
            [
                'key' => 'affiliate_commission_earned',
                'channel' => 'whatsapp',
                'journey_type' => 'affiliate_alert',
                'emoji' => '💸',
                'body' => "Good news from TALK to CAS. A referred user redeemed a voucher and your commission event has been logged. Check your dashboard for the latest earnings and payout status.",
                'is_active' => true,
                'sort_order' => 40,
                'category' => 'marketing',
                'language' => 'en_GB',
            ],
            [
                'key' => 'tagged_venue_invite',
                'channel' => 'whatsapp',
                'journey_type' => 'venue_tag',
                'emoji' => '🎯',
                'body' => "A customer tagged your venue on TALK to CAS. Join now to offer ride and food incentives, get discovered nearby, and activate your tagged-venue reward flow.",
                'is_active' => true,
                'sort_order' => 50,
                'category' => 'marketing',
                'language' => 'en_GB',
            ],
            [
                'key' => 'area_launch_alert',
                'channel' => 'whatsapp',
                'journey_type' => 'area_launch',
                'emoji' => '📍',
                'body' => "TALK to CAS is now live near you. New venues in your area are ready with launch offers, so open the app and check what is available tonight.",
                'is_active' => true,
                'sort_order' => 60,
                'category' => 'marketing',
                'language' => 'en_GB',
            ],
        ];
    }

    private function transformTemplate(CasMessageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'key' => $template->key,
            'channel' => $template->channel,
            'journey_type' => $template->journey_type,
            'weather_condition' => $template->weather_condition,
            'emoji' => $template->emoji,
            'body' => $template->body,
            'is_active' => (bool) $template->is_active,
            'sort_order' => (int) $template->sort_order,
            'category' => $template->category,
            'language' => $template->language,
            'provider_template_id' => $template->provider_template_id,
            'provider_template_name' => $template->provider_template_name,
            'approval_status' => $template->approval_status,
            'approval_notes' => $template->approval_notes,
            'last_submitted_at' => $template->last_submitted_at?->toDateTimeString(),
            'last_synced_at' => $template->last_synced_at?->toDateTimeString(),
            'created_at' => $template->created_at?->toDateTimeString(),
            'updated_at' => $template->updated_at?->toDateTimeString(),
        ];
    }

    private function normalizeAttributes(array $attributes, bool $creating): array
    {
        $normalized = [
            'key' => trim((string) ($attributes['key'] ?? '')),
            'channel' => $this->nullableString($attributes['channel'] ?? 'whatsapp'),
            'journey_type' => $this->nullableString($attributes['journey_type'] ?? null),
            'weather_condition' => $this->nullableString($attributes['weather_condition'] ?? null),
            'emoji' => $this->nullableString($attributes['emoji'] ?? null),
            'body' => trim((string) ($attributes['body'] ?? '')),
            'is_active' => array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : true,
            'sort_order' => (int) ($attributes['sort_order'] ?? 0),
            'category' => $this->nullableString($attributes['category'] ?? 'marketing'),
            'language' => $this->nullableString($attributes['language'] ?? 'en_GB'),
            'provider_template_name' => $this->nullableString($attributes['provider_template_name'] ?? null),
        ];

        if (! $creating) {
            foreach (['key', 'body'] as $requiredField) {
                if (! array_key_exists($requiredField, $attributes)) {
                    unset($normalized[$requiredField]);
                }
            }
            foreach (['channel', 'journey_type', 'weather_condition', 'emoji', 'category', 'language', 'provider_template_name'] as $nullableField) {
                if (! array_key_exists($nullableField, $attributes)) {
                    unset($normalized[$nullableField]);
                }
            }
            if (! array_key_exists('is_active', $attributes)) {
                unset($normalized['is_active']);
            }
            if (! array_key_exists('sort_order', $attributes)) {
                unset($normalized['sort_order']);
            }
        }

        if ($creating && $normalized['key'] === '') {
            throw ValidationException::withMessages([
                'key' => ['Template key is required.'],
            ]);
        }

        if ($creating && $normalized['body'] === '') {
            throw ValidationException::withMessages([
                'body' => ['Template body is required.'],
            ]);
        }

        if ($creating) {
            $normalized['approval_status'] = 'draft';
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function makeProviderTemplateId(CasMessageTemplate $template): string
    {
        return 'wa_tpl_' . $template->id . '_' . strtolower(str()->random(6));
    }

    private function makeProviderTemplateName(CasMessageTemplate $template): string
    {
        $scope = collect([
            $template->key,
            $template->channel,
            $template->journey_type,
            $template->weather_condition,
        ])->filter()->implode('_');

        return strtolower(str_replace([' ', '-'], '_', $scope));
    }

    private function buildPhaseMessage(int $approvedTemplates, int $pendingTemplates): string
    {
        if ($approvedTemplates > 0 && $pendingTemplates === 0) {
            return 'WhatsApp outbound templates are in a good state for launch. Approved templates are ready for notifications and voucher delivery flows.';
        }

        if ($pendingTemplates > 0) {
            return 'Some WhatsApp templates are still awaiting approval. Keep using inbound chat live, and finish outbound-template approval before full rollout.';
        }

        return 'Create and submit WhatsApp templates so voucher delivery, affiliate alerts, and merchant notifications can move from localhost testing into rollout prep.';
    }
}
