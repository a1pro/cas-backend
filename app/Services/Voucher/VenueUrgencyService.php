<?php

namespace App\Services\Voucher;

use App\Models\Venue;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class VenueUrgencyService
{
    public function summaryForVenue(Venue $venue): array
    {
        $window = $this->currentWindow($venue);
        $enabled = (bool) ($venue->urgency_enabled ?? config('talktocas.urgency.enabled', true));
        $cap = $enabled
            ? (int) (($venue->daily_voucher_cap ?? 0) ?: config('talktocas.urgency.default_daily_cap', 5))
            : null;

        $issuedCount = ($enabled && $cap)
            ? Voucher::query()
                ->where('venue_id', $venue->id)
                ->whereIn('status', ['issued', 'redeemed'])
                ->whereBetween('issued_at', [$window['start']->toDateTimeString(), $window['end']->toDateTimeString()])
                ->count()
            : 0;

        $remaining = $cap !== null ? max(0, $cap - $issuedCount) : null;
        $lowThreshold = (int) config('talktocas.urgency.low_inventory_threshold', 3);
        $isLowInventory = $enabled && $remaining !== null && $remaining > 0 && $remaining <= $lowThreshold;
        $isSoldOut = $enabled && $remaining !== null && $remaining <= 0;

        $urgencyMessage = null;
        if ($enabled && $remaining !== null) {
            if ($isSoldOut) {
                $urgencyMessage = 'All live vouchers for this offer window have been reserved.';
            } elseif ($isLowInventory) {
                $urgencyMessage = sprintf('Only %d voucher%s remaining in this live window.', $remaining, $remaining === 1 ? '' : 's');
            } else {
                $urgencyMessage = sprintf('%d live voucher%s remaining in this window.', $remaining, $remaining === 1 ? '' : 's');
            }
        }

        return [
            'enabled' => $enabled,
            'daily_voucher_cap' => $cap,
            'issued_count' => $issuedCount,
            'remaining_count' => $remaining,
            'is_low_inventory' => $isLowInventory,
            'is_sold_out' => $isSoldOut,
            'urgency_message' => $urgencyMessage,
            'offer_window_started_at' => $window['start']->toIso8601String(),
            'offer_window_ends_at' => $window['end']->toIso8601String(),
            'offer_window_label' => sprintf('%s → %s', $window['start']->format('d M H:i'), $window['end']->format('d M H:i')),
        ];
    }

    public function isSoldOut(Venue $venue): bool
    {
        return (bool) ($this->summaryForVenue($venue)['is_sold_out'] ?? false);
    }

    public function guardAvailability(Venue $venue): void
    {
        $summary = $this->summaryForVenue($venue);

        if ($summary['is_sold_out'] ?? false) {
            throw ValidationException::withMessages([
                'voucher' => [$summary['urgency_message'] ?: 'This offer has no live vouchers remaining right now.'],
            ]);
        }
    }

    private function currentWindow(Venue $venue): array
    {
        $timezone = config('app.timezone');
        $now = CarbonImmutable::now($timezone);

        $startTime = $venue->offer_start_time ? $this->normaliseTime((string) $venue->offer_start_time) : null;
        $endTime = $venue->offer_end_time ? $this->normaliseTime((string) $venue->offer_end_time) : null;

        if (! $startTime || ! $endTime) {
            return [
                'start' => $now->startOfDay(),
                'end' => $now->endOfDay(),
            ];
        }

        $startToday = $now->startOfDay()->setTimeFromTimeString($startTime);
        $endToday = $now->startOfDay()->setTimeFromTimeString($endTime);

        if ($startTime <= $endTime) {
            return [
                'start' => $startToday,
                'end' => $endToday,
            ];
        }

        if ($now->format('H:i:s') <= $endTime) {
            return [
                'start' => $startToday->subDay(),
                'end' => $endToday,
            ];
        }

        return [
            'start' => $startToday,
            'end' => $endToday->addDay(),
        ];
    }

    private function normaliseTime(string $value): string
    {
        return strlen($value) === 5 ? $value . ':00' : $value;
    }
}
