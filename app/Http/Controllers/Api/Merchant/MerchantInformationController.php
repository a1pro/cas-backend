<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Models\Venue;
use Illuminate\Http\Request;

class MerchantInformationController extends BaseController
{
    public function index(Request $request)
    {
        $merchant = $this->merchantForUser($request);

        return $this->success([
            'summary' => [
                'pending' => $merchant->venues()->where('approval_status', 'pending')->count(),
                'approved' => $merchant->venues()->where('approval_status', 'approved')->count(),
                'rejected' => $merchant->venues()->where('approval_status', 'rejected')->count(),
                'total' => $merchant->venues()->count(),
            ],
            'items' => $merchant->venues()
                ->orderByRaw("CASE WHEN approval_status = 'pending' THEN 0 WHEN approval_status = 'rejected' THEN 1 WHEN approval_status = 'approved' THEN 2 ELSE 3 END")
                ->latest('submitted_for_approval_at')
                ->get()
                ->map(fn (Venue $venue) => $this->transformVenue($venue))
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        $validated = $this->validateVenuePayload($request, true);

        $venue = $merchant->venues()->create(array_merge($this->normalisedVenuePayload($validated), [
            'is_active' => false,
            'approval_status' => 'pending',
            'submitted_for_approval_at' => now(),
            'approved_at' => null,
            'approved_by_user_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'offer_enabled' => false,
            'offer_value' => $validated['offer_value'] ?? 5,
            'offer_days' => ['friday', 'saturday'],
            'offer_start_time' => '18:00:00',
            'offer_end_time' => '23:59:00',
            'minimum_order' => $validated['minimum_order'] ?? ($this->isFoodBusiness($validated['category']) ? 25 : null),
            'fulfilment_type' => $this->isFoodBusiness($validated['category']) ? 'delivery' : 'venue',
            'offer_review_status' => 'draft',
            'offer_type' => $validated['offer_type'] ?? ($this->isFoodBusiness($validated['category']) ? 'food' : 'ride'),
            'ride_trip_type' => $validated['ride_trip_type'] ?? 'to_venue',
        ]));

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh()),
            'summary' => [
                'pending' => $merchant->venues()->where('approval_status', 'pending')->count(),
                'approved' => $merchant->venues()->where('approval_status', 'approved')->count(),
                'rejected' => $merchant->venues()->where('approval_status', 'rejected')->count(),
                'total' => $merchant->venues()->count(),
            ],
        ], 'Venue information submitted for admin approval.', 201);
    }

    public function update(Request $request, Venue $venue)
    {
        $merchant = $this->merchantForUser($request);

        if ((int) $venue->merchant_id !== (int) $merchant->id) {
            return $this->error('Venue does not belong to this merchant.', 403);
        }

        if ($venue->approval_status === 'approved') {
            return $this->error('Approved venue information cannot be changed here. Please request an admin address/profile change.', 422);
        }

        $validated = $this->validateVenuePayload($request, false);

        $venue->update(array_merge($this->normalisedVenuePayload($validated, $venue), [
            'is_active' => false,
            'approval_status' => 'pending',
            'submitted_for_approval_at' => now(),
            'rejected_at' => null,
            'rejection_reason' => null,
        ]));

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh()),
        ], 'Venue information updated and re-submitted for admin approval.');
    }

    public function destroy(Request $request, Venue $venue)
    {
        $merchant = $this->merchantForUser($request);

        if ((int) $venue->merchant_id !== (int) $merchant->id) {
            return $this->error('Venue does not belong to this merchant.', 403);
        }

        if ($venue->approval_status === 'approved' || $venue->vouchers()->exists()) {
            return $this->error('Approved venues or venues with voucher history cannot be deleted by merchant.', 422);
        }

        $venue->delete();

        return $this->success([], 'Venue information removed successfully.');
    }

    private function validateVenuePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'category' => [$creating ? 'required' : 'sometimes', 'in:club,bar,restaurant,takeaway,cafe'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => [$creating ? 'required' : 'sometimes', 'string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:1200'],
            'promo_message' => ['nullable', 'string', 'max:500'],
            'offer_type' => ['nullable', 'in:ride,food,dual_choice,dual'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'offer_value' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'minimum_order' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ]);
    }

    private function normalisedVenuePayload(array $validated, ?Venue $venue = null): array
    {
        $category = $validated['category'] ?? $venue?->category ?? 'restaurant';
        $offerType = $validated['offer_type'] ?? $venue?->offer_type ?? ($this->isFoodBusiness($category) ? 'food' : 'ride');

        return [
            'name' => array_key_exists('name', $validated) ? trim($validated['name']) : $venue?->name,
            'category' => $category,
            'address' => array_key_exists('address', $validated) ? ($validated['address'] ?? null) : $venue?->address,
            'city' => array_key_exists('city', $validated) ? ($validated['city'] ?? null) : $venue?->city,
            'postcode' => array_key_exists('postcode', $validated) ? strtoupper(trim($validated['postcode'])) : $venue?->postcode,
            'latitude' => array_key_exists('latitude', $validated) ? ($validated['latitude'] ?? null) : $venue?->latitude,
            'longitude' => array_key_exists('longitude', $validated) ? ($validated['longitude'] ?? null) : $venue?->longitude,
            'description' => array_key_exists('description', $validated) ? ($validated['description'] ?? null) : $venue?->description,
            'promo_message' => array_key_exists('promo_message', $validated) ? ($validated['promo_message'] ?? null) : $venue?->promo_message,
            'offer_type' => $offerType === 'dual' ? 'dual_choice' : $offerType,
            'ride_trip_type' => $validated['ride_trip_type'] ?? $venue?->ride_trip_type ?? 'to_venue',
            'offer_value' => $validated['offer_value'] ?? $venue?->offer_value,
            'minimum_order' => array_key_exists('minimum_order', $validated) ? ($validated['minimum_order'] ?? null) : $venue?->minimum_order,
        ];
    }

    private function transformVenue(Venue $venue): array
    {
        return [
            'id' => $venue->id,
            'merchant_id' => $venue->merchant_id,
            'name' => $venue->name,
            'category' => $venue->category,
            'address' => $venue->address,
            'city' => $venue->city,
            'postcode' => $venue->postcode,
            'latitude' => $venue->latitude !== null ? (float) $venue->latitude : null,
            'longitude' => $venue->longitude !== null ? (float) $venue->longitude : null,
            'description' => $venue->description,
            'promo_message' => $venue->promo_message,
            'approval_status' => $venue->approval_status ?: ((bool) $venue->is_active ? 'approved' : 'pending'),
            'venue_code' => $venue->venue_code,
            'is_active' => (bool) $venue->is_active,
            'submitted_for_approval_at' => optional($venue->submitted_for_approval_at)?->toIso8601String(),
            'approved_at' => optional($venue->approved_at)?->toIso8601String(),
            'rejected_at' => optional($venue->rejected_at)?->toIso8601String(),
            'rejection_reason' => $venue->rejection_reason,
            'offer_type' => $venue->offer_type,
            'ride_trip_type' => $venue->ride_trip_type,
            'offer_value' => $venue->offer_value !== null ? number_format((float) $venue->offer_value, 2, '.', '') : null,
            'minimum_order' => $venue->minimum_order !== null ? number_format((float) $venue->minimum_order, 2, '.', '') : null,
        ];
    }

    private function merchantForUser(Request $request): Merchant
    {
        return Merchant::with(['wallet', 'venues'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function isFoodBusiness(?string $businessType): bool
    {
        return in_array($businessType, ['restaurant', 'takeaway', 'cafe'], true);
    }
}
