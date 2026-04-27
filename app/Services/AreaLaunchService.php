<?php

namespace App\Services;

use App\Models\LeadCapture;
use App\Models\LiveAreaPostcode;
use App\Models\User;

class AreaLaunchService
{
    public function isLiveArea(?string $postcode): bool
    {
        if (! (bool) config('talktocas.live_areas.enabled', true)) {
            return true;
        }

        if (! $postcode) {
            return true;
        }

        return (bool) $this->matchArea($postcode);
    }

    public function liveAreaContext(?string $postcode): array
    {
        $enabled = (bool) config('talktocas.live_areas.enabled', true);

        if (! $enabled) {
            return [
                'enabled' => false,
                'is_live' => true,
                'matched_area' => null,
                'message' => 'Live area restriction disabled.',
                'waiting_list_cta' => null,
            ];
        }

        if (! $postcode) {
            return [
                'enabled' => true,
                'is_live' => true,
                'matched_area' => null,
                'message' => 'No postcode supplied yet.',
                'waiting_list_cta' => null,
            ];
        }

        $match = $this->matchArea($postcode);

        if ($match) {
            return [
                'enabled' => true,
                'is_live' => true,
                'matched_area' => [
                    'postcode' => $match->postcode_prefix,
                    'label' => $match->label,
                    'priority' => (int) $match->sort_order,
                ],
                'message' => $match->label ? "Live in {$match->label}." : 'Live area matched.',
                'waiting_list_cta' => null,
            ];
        }

        return [
            'enabled' => true,
            'is_live' => false,
            'matched_area' => null,
            'message' => 'We are not live in this area yet.',
            'waiting_list_cta' => [
                'headline' => 'Coming soon to your area',
                'message' => 'Tag your favourite venue or join the waiting list and we will let you know when TALK to CAS launches nearby.',
                'lead_generator_path' => '/lead-generator',
                'tag_button_path' => '/tag',
            ],
        ];
    }

    public function audienceSummary(?string $postcode): array
    {
        $prefix = $this->extractPostcodePrefix($postcode);
        if (! $prefix) {
            return [
                'postcode_prefix' => null,
                'registered_users' => 0,
                'waiting_list_leads' => 0,
            ];
        }

        return [
            'postcode_prefix' => $prefix,
            'registered_users' => User::query()->whereNotNull('postcode')->whereRaw('UPPER(postcode) like ?', [$prefix . '%'])->count(),
            'waiting_list_leads' => LeadCapture::query()->whereNotNull('postcode')->whereRaw('UPPER(postcode) like ?', [$prefix . '%'])->count(),
        ];
    }

    public function extractPostcodePrefix(?string $postcode): ?string
    {
        if (! $postcode) {
            return null;
        }

        $clean = strtoupper(trim($postcode));
        if ($clean === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $clean);

        return $parts[0] ?? $clean;
    }

    private function matchArea(?string $postcode): ?LiveAreaPostcode
    {
        $normalized = strtoupper(trim((string) $postcode));
        if ($normalized === '') {
            return null;
        }

        $prefix = $this->extractPostcodePrefix($normalized);

        return LiveAreaPostcode::query()
            ->where('is_active', true)
            ->where(function ($query) use ($normalized, $prefix) {
                $query->whereRaw('UPPER(postcode_prefix) = ?', [$normalized]);
                if ($prefix) {
                    $query->orWhereRaw('UPPER(postcode_prefix) = ?', [$prefix]);
                }
            })
            ->orderByDesc('sort_order')
            ->first();
    }
}
