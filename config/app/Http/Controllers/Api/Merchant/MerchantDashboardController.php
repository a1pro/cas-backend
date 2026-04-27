<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Models\Voucher;
use App\Models\WalletTransaction;
use App\Services\Weather\OpenWeatherService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantDashboardController extends BaseController
{
    public function __construct(private readonly OpenWeatherService $weatherService)
    {
    }

    public function dashboard(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        $primaryVenue = $this->primaryVenueForMerchant($merchant);

        return $this->success([
            'merchant' => $merchant->load('wallet'),
            'stats' => [
                'active_venues' => $merchant->venues()->where('is_active', true)->count(),
                'issued_vouchers' => $merchant->vouchers()->where('status', 'issued')->count(),
                'redeemed_vouchers' => $merchant->vouchers()->where('status', 'redeemed')->count(),
                'wallet_balance' => $merchant->wallet->balance,
            ],
            'primary_venue' => $primaryVenue,
            'offer_snapshot' => $primaryVenue ? $this->offerPayload($merchant, $primaryVenue) : null,
            'recent_vouchers' => Voucher::with(['venue', 'user'])
                ->where('merchant_id', $merchant->id)
                ->latest()
                ->take(10)
                ->get(),
            'recent_transactions' => WalletTransaction::where('merchant_id', $merchant->id)
                ->latest()
                ->take(10)
                ->get(),
        ]);
    }

    public function offerSettings(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        $venue = $this->primaryVenueForMerchant($merchant);

        return $this->success($this->offerPayload($merchant, $venue));
    }

    public function updateOfferSettings(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        $wallet = $merchant->wallet;
        $venue = $this->primaryVenueForMerchant($merchant);

        $validated = $request->validate([
            'offer_enabled' => ['required', 'boolean'],
            'business_type' => ['required', 'in:club,bar,restaurant'],
            'offer_type' => ['nullable', 'in:food,ride,dual_choice'],
            'voucher_amount' => ['required', 'numeric', 'min:1', 'max:500'],
            'offer_days' => ['required', 'array', 'min:1'],
            'offer_days.*' => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'fulfilment_type' => ['required', 'in:venue,collection,delivery,both'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'low_balance_threshold' => ['required', 'numeric', 'min:1', 'max:100000'],
        ]);

        $offerType = $validated['offer_type'] ?? ($validated['business_type'] === 'restaurant' ? 'food' : 'ride');

        if ($validated['business_type'] !== 'restaurant' && $offerType !== 'dual_choice') {
            $validated['minimum_order'] = null;
            $validated['fulfilment_type'] = 'venue';
        }

        $days = collect($validated['offer_days'])
            ->map(fn ($day) => strtolower(trim($day)))
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($merchant, $wallet, $venue, $validated, $days, $offerType) {
            $merchant->update([
                'business_type' => $validated['business_type'],
            ]);

            $wallet->update([
                'low_balance_threshold' => $validated['low_balance_threshold'],
            ]);

            $venue->update([
                'category' => $validated['business_type'],
                'offer_enabled' => $validated['offer_enabled'],
                'offer_value' => $validated['voucher_amount'],
                'offer_days' => $days,
                'offer_start_time' => $validated['start_time'] ?? null,
                'offer_end_time' => $validated['end_time'] ?? null,
                'minimum_order' => $validated['minimum_order'] ?? null,
                'fulfilment_type' => $validated['fulfilment_type'],
                'offer_review_status' => 'live',
                'offer_type' => $offerType,
                'ride_trip_type' => $validated['ride_trip_type'] ?? null,
            ]);
        });

        return $this->success(
            $this->offerPayload($merchant->fresh(['wallet', 'venues']), $venue->fresh()),
            'Offer settings saved successfully.'
        );
    }

    public function venueProfile(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        $venue = $this->primaryVenueForMerchant($merchant);

        return $this->success($this->venuePayload($merchant, $venue));
    }

    public function updateVenueProfile(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        $venue = $this->primaryVenueForMerchant($merchant);

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'in:club,bar,restaurant'],
            'venue_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:1200'],
        ]);

        $postcode = strtoupper(trim($validated['postcode']));
        $latitude = isset($validated['latitude']) ? (float) $validated['latitude'] : null;
        $longitude = isset($validated['longitude']) ? (float) $validated['longitude'] : null;

        if (($latitude === null || $longitude === null) && $this->weatherService->enabled()) {
            $geo = $this->weatherService->geocodePostcode($postcode);
            if ($geo) {
                $latitude = $latitude ?? (float) $geo['latitude'];
                $longitude = $longitude ?? (float) $geo['longitude'];
            }
        }

        DB::transaction(function () use ($merchant, $venue, $validated, $postcode, $latitude, $longitude) {
            $merchant->update([
                'business_name' => $validated['business_name'],
                'business_type' => $validated['business_type'],
            ]);

            $venue->update([
                'name' => $validated['venue_name'],
                'category' => $validated['business_type'],
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'postcode' => $postcode,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'description' => $validated['description'] ?? null,
            ]);
        });

        return $this->success($this->venuePayload($merchant->fresh(['wallet', 'venues']), $venue->fresh()), 'Venue profile saved successfully.');
    }

    public function venues(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        return $this->success($merchant->venues()->orderBy('name')->get());
    }

    public function walletTransactions(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        return $this->success(WalletTransaction::where('merchant_id', $merchant->id)->latest()->get());
    }

    public function vouchers(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        return $this->success(Voucher::with(['venue', 'user'])->where('merchant_id', $merchant->id)->latest()->get());
    }

    public function topUp(Request $request)
    {
        $merchant = $this->merchantForUser($request);
        $wallet = $merchant->wallet;

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $before = (float) $wallet->balance;
        $after = $before + (float) $validated['amount'];

        $wallet->update(['balance' => $after]);

        WalletTransaction::create([
            'merchant_id' => $merchant->id,
            'merchant_wallet_id' => $wallet->id,
            'type' => 'deposit',
            'amount' => $validated['amount'],
            'balance_before' => $before,
            'balance_after' => $after,
            'reference' => 'TOPUP-' . strtoupper(Str::random(6)),
            'notes' => 'Manual top-up from merchant dashboard',
        ]);

        return $this->success($wallet->fresh(), 'Wallet topped up successfully');
    }

    public function redeemVoucher(Request $request, Voucher $voucher)
    {
        $merchant = $this->merchantForUser($request);
        $wallet = $merchant->wallet;

        if ((int) $voucher->merchant_id !== (int) $merchant->id) {
            return $this->error('Voucher does not belong to this merchant', 403);
        }

        if ($voucher->status === 'redeemed') {
            return $this->error('Voucher already redeemed', 422);
        }

        $charge = (float) $voucher->total_charge;
        $before = (float) $wallet->balance;

        if ($before < $charge) {
            return $this->error('Insufficient wallet balance', 422);
        }

        $after = $before - $charge;

        $wallet->update([
            'balance' => $after,
            'last_alert_at' => $after < (float) $wallet->low_balance_threshold ? now() : $wallet->last_alert_at,
        ]);

        $voucher->update([
            'status' => 'redeemed',
            'redeemed_at' => now(),
            'external_reference' => 'UBER-' . strtoupper(Str::random(10)),
        ]);

        WalletTransaction::create([
            'merchant_id' => $merchant->id,
            'merchant_wallet_id' => $wallet->id,
            'voucher_id' => $voucher->id,
            'type' => 'debit',
            'amount' => $charge,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference' => 'REDEEM-' . strtoupper(Str::random(6)),
            'notes' => 'Wallet charged after verified voucher redemption',
        ]);

        return $this->success([
            'wallet' => $wallet->fresh(),
            'voucher' => $voucher->fresh(['venue', 'user']),
            'low_balance_alert' => $after < (float) $wallet->low_balance_threshold,
        ], 'Voucher redeemed and wallet charged successfully');
    }

    private function merchantForUser(Request $request): Merchant
    {
        return Merchant::with(['wallet', 'venues'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function primaryVenueForMerchant(Merchant $merchant)
    {
        return $merchant->venues()->orderBy('id')->first();
    }

    private function venuePayload(Merchant $merchant, $venue): array
    {
        return [
            'merchant' => [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
                'business_type' => $merchant->business_type,
            ],
            'venue' => [
                'id' => $venue->id,
                'name' => $venue->name,
                'category' => $venue->category,
                'address' => $venue->address,
                'postcode' => $venue->postcode,
                'city' => $venue->city,
                'latitude' => $venue->latitude !== null ? (float) $venue->latitude : null,
                'longitude' => $venue->longitude !== null ? (float) $venue->longitude : null,
                'description' => $venue->description,
            ],
        ];
    }

    private function offerPayload(Merchant $merchant, $venue): array
    {
        return [
            'merchant' => [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
                'business_type' => $merchant->business_type,
            ],
            'venue' => [
                'id' => $venue->id,
                'name' => $venue->name,
                'category' => $venue->category,
                'postcode' => $venue->postcode,
                'city' => $venue->city,
                'address' => $venue->address,
                'latitude' => $venue->latitude !== null ? (float) $venue->latitude : null,
                'longitude' => $venue->longitude !== null ? (float) $venue->longitude : null,
                'description' => $venue->description,
            ],
            'offer' => [
                'offer_enabled' => (bool) $venue->offer_enabled,
                'voucher_amount' => number_format((float) ($venue->offer_value ?? 5), 2, '.', ''),
                'offer_days' => Arr::wrap($venue->offer_days ?: ['friday', 'saturday']),
                'start_time' => $venue->offer_start_time ? substr((string) $venue->offer_start_time, 0, 5) : null,
                'end_time' => $venue->offer_end_time ? substr((string) $venue->offer_end_time, 0, 5) : null,
                'minimum_order' => $venue->minimum_order !== null ? number_format((float) $venue->minimum_order, 2, '.', '') : null,
                'fulfilment_type' => $venue->fulfilment_type ?: 'venue',
                'offer_type' => $venue->offer_type ?: ($merchant->business_type === 'restaurant' ? 'food' : 'ride'),
                'ride_trip_type' => $venue->ride_trip_type,
                'review_status' => $venue->offer_review_status ?: 'live',
            ],
            'wallet' => [
                'low_balance_threshold' => number_format((float) $merchant->wallet->low_balance_threshold, 2, '.', ''),
                'balance' => number_format((float) $merchant->wallet->balance, 2, '.', ''),
            ],
        ];
    }
}
