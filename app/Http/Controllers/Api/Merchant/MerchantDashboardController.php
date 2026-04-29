<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Venue;
use App\Models\Voucher;
use App\Models\WalletTransaction;
use App\Services\Affiliate\AffiliateCommissionService;
use App\Services\Affiliate\AffiliateTrackingService;
use App\Services\Lead\LeadGeneratorService;
use App\Services\Merchant\MerchantBusinessRuleService;
use App\Services\Merchant\MerchantOfferSyncService;
use App\Services\Merchant\VenueAddressApprovalService;
use App\Services\Payments\StripeFinanceService;
use App\Services\Voucher\ProviderVoucherLinkService;
use App\Services\Voucher\VoucherProviderVerificationService;
use App\Services\Voucher\VenueUrgencyService;
use App\Services\Wallet\MerchantWalletAlertService;
use App\Services\Weather\OpenWeatherService;
use App\Services\Weather\WeatherBehaviourService;
use App\Support\OfferRules;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantDashboardController extends BaseController
{
    public function __construct(
        private readonly OpenWeatherService $weatherService,
        private readonly MerchantWalletAlertService $walletAlertService,
        private readonly AffiliateTrackingService $affiliateTrackingService,
        private readonly AffiliateCommissionService $affiliateCommissionService,
        private readonly VoucherProviderVerificationService $providerVerificationService,
        private readonly VenueUrgencyService $venueUrgencyService,
        private readonly LeadGeneratorService $leadGeneratorService,
        private readonly WeatherBehaviourService $weatherBehaviourService,
        private readonly StripeFinanceService $stripeFinanceService,
        private readonly MerchantOfferSyncService $offerSyncService,
        private readonly MerchantBusinessRuleService $merchantBusinessRuleService,
        private readonly VenueAddressApprovalService $addressApprovalService,
        private readonly ProviderVoucherLinkService $providerVoucherLinkService,
    ) {
    }

    public function dashboard(Request $request)
    {
        try {
            $merchant = $this->merchantForUser($request);
            $primaryVenue = $this->primaryVenueForMerchant($merchant);

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => [
                        'merchant' => [
                            'id' => $merchant->id,
                            'business_name' => $merchant->business_name,
                            'business_type' => $merchant->business_type,
                            'status' => $merchant->status,
                            'joined_at' => optional($merchant->created_at)?->toDateTimeString(),
                            'onboarding_plan' => $merchant->onboarding_plan,
                            'free_trial_status' => $merchant->free_trial_status,
                            'free_trial_message' => $merchant->free_trial_message,
                            'free_trial_ineligible_reason' => $merchant->free_trial_ineligible_reason,
                            'trial_blocked_keywords' => $merchant->trial_blocked_keywords ?? [],
                            'wallet' => $this->walletPayload($merchant),
                            'business_rules' => $this->merchantBusinessRulesPayload($merchant),
                        ],
                        'stats' => [
                            'active_venues' => $merchant->venues()->where('is_active', true)->count(),
                            'issued_vouchers' => $merchant->vouchers()->where('status', 'issued')->count(),
                            'redeemed_vouchers' => $merchant->vouchers()->where('status', 'redeemed')->count(),
                            'wallet_balance' => number_format((float) $merchant->wallet->balance, 2, '.', ''),
                        ],
                        'primary_venue' => $primaryVenue,
                        'wallet_status' => $this->walletAlertService->statusForWallet($merchant->wallet),
                        'stripe_finance' => $this->stripeFinanceService->merchantPayload($merchant),
                        'provider_verification' => [
                            'summary' => $this->providerVerificationService->summaryForMerchant($merchant),
                            'pending_vouchers' => $this->providerVerificationService->pendingVoucherPayloads(
                                $merchant,
                                (int) config('talktocas.provider_verification.dashboard_pending_limit', 5)
                            ),
                            'recent_events' => $this->providerVerificationService->recentEventPayloads(
                                $merchant,
                                (int) config('talktocas.provider_verification.dashboard_recent_event_limit', 10)
                            ),
                        ],
                        'lead_generator' => $this->leadGeneratorService->summaryForMerchant(
                            $merchant,
                            (int) config('talktocas.lead_generator.merchant_recent_limit', 5)
                        ),
                        'weather_behaviour' => $this->weatherBehaviourService->merchantVenueInsights($primaryVenue, $merchant->business_type),
                        'urgency_inventory' => $primaryVenue ? $this->venueUrgencyService->summaryForVenue($primaryVenue) : null,
                        'offer_snapshot' => $primaryVenue ? $this->offerPayload($merchant, $primaryVenue) : null,
                        'offer_sync' => $this->offerSyncService->merchantPayload($merchant, $primaryVenue),
                        'address_change' => $this->addressApprovalService->merchantPayload($primaryVenue),
                        'exact_voucher_links' => $this->providerVoucherLinkService->merchantSummary($merchant, $primaryVenue),
                        'recent_vouchers' => Voucher::with(['venue', 'user'])
                            ->where('merchant_id', $merchant->id)
                            ->latest()
                            ->take(10)
                            ->get(),
                        'recent_transactions' => WalletTransaction::where('merchant_id', $merchant->id)
                            ->latest()
                            ->take(10)
                            ->get(),
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function offerSettings(Request $request)
    {
        try {
            $merchant = $this->merchantForUser($request);
            $venue = $this->primaryVenueForMerchant($merchant);

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $this->offerPayload($merchant, $venue),
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function updateOfferSettings(Request $request)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);
            $wallet = $merchant->wallet;
            $venue = $this->primaryVenueForMerchant($merchant);

            $validated = $request->validate([
                'offer_enabled' => ['required', 'boolean'],
                'business_type' => ['required', 'in:club,bar,restaurant,takeaway,cafe'],
                'offer_type' => ['required', 'in:food,ride,dual_choice'],
                'voucher_amount' => ['required', 'numeric', 'min:1', 'max:100'],
                'offer_days' => ['required', 'array', 'min:1'],
                'offer_days.*' => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
                'start_time' => ['nullable', 'date_format:H:i'],
                'end_time' => ['nullable', 'date_format:H:i'],
                'minimum_order' => ['nullable', 'numeric', 'min:0'],
                'fulfilment_type' => ['nullable', 'in:venue,collection,delivery,both'],
                'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
                'low_balance_threshold' => ['required', 'numeric', 'min:1', 'max:100000'],
                'auto_top_up_enabled' => ['nullable', 'boolean'],
                'auto_top_up_amount' => ['nullable', 'numeric', 'min:1', 'max:100000'],
                'urgency_enabled' => ['nullable', 'boolean'],
                'daily_voucher_cap' => ['nullable', 'integer', 'min:1', 'max:500'],
            ]);

            $offerType = $validated['offer_type'];
            $range = OfferRules::voucherRangeForBusiness($validated['business_type']);
            $voucherAmount = (float) $validated['voucher_amount'];

            if ($voucherAmount < $range['min'] || $voucherAmount > $range['max']) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => OfferRules::voucherAmountMessage($validated['business_type']),
                    'errors' => [
                            'voucher_amount' => [OfferRules::voucherAmountMessage($validated['business_type'])],
                        ],
                ], 422);
            }

            if (OfferRules::offerTypeSupportsRide($offerType) && blank($validated['ride_trip_type'] ?? null)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Choose whether the ride voucher is 1 Trip or 2 Trips (to-and-from).',
                    'errors' => [
                            'ride_trip_type' => ['Ride trip type is required for ride-only and dual-choice offers.'],
                        ],
                ], 422);
            }

            if (! OfferRules::offerTypeSupportsRide($offerType)) {
                $validated['ride_trip_type'] = null;
            }

            $serviceFee = (float) ($merchant->default_service_fee ?? 2.50);
            $requiredBalance = OfferRules::minimumWalletBalanceForOffer(
                $offerType,
                $voucherAmount,
                $serviceFee,
                $validated['ride_trip_type'] ?? null
            );

            if ($requiredBalance > 0 && (float) $wallet->balance < $requiredBalance) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'This offer cannot be saved because the current wallet balance is too low for the selected trip mode.',
                    'errors' => [
                            'wallet_balance' => [
                                sprintf(
                                    'At least £%.2f is required for %s.',
                                    $requiredBalance,
                                    OfferRules::rideTripTypeLabel($validated['ride_trip_type'] ?? null)
                                ),
                            ],
                        ],
                ], 422);
            }

            $minimumTopUpAmount = $this->merchantBusinessRuleService->minimumTopUpAmount();
            $autoTopUpEnabled = (bool) ($validated['auto_top_up_enabled'] ?? false);
            $autoTopUpAmount = (float) ($validated['auto_top_up_amount'] ?? ($wallet->auto_top_up_amount ?? $minimumTopUpAmount));

            if ($autoTopUpEnabled && $autoTopUpAmount < $minimumTopUpAmount) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => sprintf('Auto top-up amount must be at least £%.2f.', $minimumTopUpAmount),
                    'errors' => [
                            'auto_top_up_amount' => [sprintf('Auto top-up amount must be at least £%.2f.', $minimumTopUpAmount)],
                        ],
                ], 422);
            }

            $urgencyEnabled = (bool) ($validated['urgency_enabled'] ?? ($venue->urgency_enabled ?? config('talktocas.urgency.enabled', true)));
            $dailyVoucherCap = $urgencyEnabled
                ? (int) (($validated['daily_voucher_cap'] ?? $venue->daily_voucher_cap) ?: config('talktocas.urgency.default_daily_cap', 5))
                : null;

            if (! OfferRules::offerTypeSupportsFood($offerType)) {
                $validated['minimum_order'] = null;
                $validated['fulfilment_type'] = 'venue';
            }

            if (OfferRules::offerTypeSupportsFood($offerType) && blank($validated['minimum_order'] ?? null)) {
                $validated['minimum_order'] = 25;
            }

            if (OfferRules::offerTypeSupportsFood($offerType) && blank($validated['fulfilment_type'] ?? null)) {
                $validated['fulfilment_type'] = 'collection';
            }

            $days = collect($validated['offer_days'])
                ->map(fn ($day) => strtolower(trim($day)))
                ->unique()
                ->values()
                ->all();

            $previousSnapshot = [
                'merchant' => [
                    'business_type' => $merchant->business_type,
                ],
                'wallet' => [
                    'low_balance_threshold' => (float) $wallet->low_balance_threshold,
                    'auto_top_up_enabled' => (bool) $wallet->auto_top_up_enabled,
                    'auto_top_up_amount' => (float) $wallet->auto_top_up_amount,
                ],
                'venue' => [
                    'category' => $venue->category,
                    'offer_enabled' => (bool) $venue->offer_enabled,
                    'offer_value' => (float) ($venue->offer_value ?? 0),
                    'offer_days' => Arr::wrap($venue->offer_days),
                    'offer_start_time' => $venue->offer_start_time ? substr((string) $venue->offer_start_time, 0, 5) : null,
                    'offer_end_time' => $venue->offer_end_time ? substr((string) $venue->offer_end_time, 0, 5) : null,
                    'minimum_order' => $venue->minimum_order !== null ? (float) $venue->minimum_order : null,
                    'fulfilment_type' => $venue->fulfilment_type,
                    'offer_type' => $venue->offer_type,
                    'ride_trip_type' => $venue->ride_trip_type,
                ],
            ];

            $requestedSnapshot = [
                'merchant' => [
                    'business_type' => $validated['business_type'],
                ],
                'wallet' => [
                    'low_balance_threshold' => (float) $validated['low_balance_threshold'],
                    'auto_top_up_enabled' => $autoTopUpEnabled,
                    'auto_top_up_amount' => $autoTopUpAmount,
                ],
                'venue' => [
                    'category' => $validated['business_type'],
                    'offer_enabled' => (bool) $validated['offer_enabled'],
                    'offer_value' => $voucherAmount,
                    'offer_days' => $days,
                    'offer_start_time' => $validated['start_time'] ?? null,
                    'offer_end_time' => $validated['end_time'] ?? null,
                    'minimum_order' => $validated['minimum_order'] ?? null,
                    'fulfilment_type' => $validated['fulfilment_type'] ?? ($offerType === 'ride' ? 'venue' : null),
                    'offer_type' => $offerType,
                    'ride_trip_type' => $validated['ride_trip_type'] ?? null,
                ],
            ];

            $changedFields = $this->offerSyncChangedFields($previousSnapshot, $requestedSnapshot);
            $requiresOfferSync = ! empty($changedFields);

            DB::transaction(function () use ($merchant, $wallet, $venue, $validated, $days, $offerType, $voucherAmount, $autoTopUpEnabled, $autoTopUpAmount, $urgencyEnabled, $dailyVoucherCap, $requiresOfferSync) {
                $merchant->update([
                    'business_type' => $validated['business_type'],
                ]);

                $wallet->update([
                    'low_balance_threshold' => $validated['low_balance_threshold'],
                    'auto_top_up_enabled' => $autoTopUpEnabled,
                    'auto_top_up_amount' => $autoTopUpAmount,
                ]);

                $venue->update([
                    'category' => $validated['business_type'],
                    'offer_enabled' => $validated['offer_enabled'],
                    'offer_value' => $voucherAmount,
                    'offer_days' => $days,
                    'offer_start_time' => $validated['start_time'] ?? null,
                    'offer_end_time' => $validated['end_time'] ?? null,
                    'minimum_order' => $validated['minimum_order'] ?? null,
                    'fulfilment_type' => $validated['fulfilment_type'] ?? ($offerType === 'ride' ? 'venue' : null),
                    'offer_review_status' => $requiresOfferSync ? 'pending_sync' : 'live',
                    'offer_type' => $offerType,
                    'ride_trip_type' => $validated['ride_trip_type'] ?? null,
                    'urgency_enabled' => $urgencyEnabled,
                    'daily_voucher_cap' => $dailyVoucherCap,
                ]);
            });
            if ($requiresOfferSync) {
                $this->offerSyncService->createOrReplacePendingRequest(
                    $merchant->fresh(['wallet']),
                    $venue->fresh(),
                    $wallet->fresh(),
                    $request->user(),
                    $previousSnapshot,
                    $requestedSnapshot,
                    $changedFields
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => $requiresOfferSync
                    ? 'Offer settings saved locally and queued for Uber for Business sync review.'
                    : 'Offer settings saved successfully.',
                'data' => $this->offerPayload($merchant->fresh(['wallet', 'venues']), $venue->fresh()),
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function venueProfile(Request $request)
    {
        try {
            $merchant = $this->merchantForUser($request);
            $venue = $this->primaryVenueForMerchant($merchant);

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $this->venuePayload($merchant, $venue),
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function updateVenueProfile(Request $request)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);
            $venue = $this->primaryVenueForMerchant($merchant);

            $validated = $request->validate([
                'business_name' => ['required', 'string', 'max:255'],
                'business_type' => ['required', 'in:club,bar,restaurant,takeaway,cafe'],
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

            $addressHasChanged = $this->addressHasChanged($venue, [
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'postcode' => $postcode,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            DB::transaction(function () use ($merchant, $venue, $validated) {
                $merchant->update([
                    'business_name' => $validated['business_name'],
                    'business_type' => $validated['business_type'],
                ]);

                $venue->update([
                    'name' => $validated['venue_name'],
                    'category' => $validated['business_type'],
                    'description' => $validated['description'] ?? null,
                ]);
            });
            if ($addressHasChanged) {
                $requestRecord = $this->addressApprovalService->createOrReplacePendingRequest(
                    $merchant->fresh(['wallet']),
                    $venue->fresh(),
                    $request->user(),
                    [
                        'address' => $venue->address,
                        'city' => $venue->city,
                        'postcode' => $venue->postcode,
                        'latitude' => $venue->latitude !== null ? (float) $venue->latitude : null,
                        'longitude' => $venue->longitude !== null ? (float) $venue->longitude : null,
                    ],
                    [
                        'address' => $validated['address'] ?? null,
                        'city' => $validated['city'] ?? null,
                        'postcode' => $postcode,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                    (string) config('talktocas.merchant_rules.address_change_support_message')
                );

                DB::commit();

                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => $requestRecord->support_message ?? 'Address change request submitted successfully.',
                    'data' => $this->venuePayload($merchant->fresh(['wallet', 'venues']), $venue->fresh()),
                ], 200);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue profile saved successfully.',
                'data' => $this->venuePayload($merchant->fresh(['wallet', 'venues']), $venue->fresh()),
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function venues(Request $request)
    {
        try {
            $merchant = $this->merchantForUser($request);
            $status = strtolower((string) $request->query('status', 'all'));
            $category = strtolower((string) $request->query('category', ''));
            $search = trim((string) $request->query('search', $request->query('q', '')));

            $query = $merchant->venues()
                ->when($status !== '' && $status !== 'all', function ($query) use ($status) {
                    $query->where('approval_status', $status);
                })
                ->when($category !== '' && $category !== 'all', function ($query) use ($category) {
                    $query->where('category', $category);
                })
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%' . $search . '%';
                    $query->where(function ($inner) use ($like) {
                        $inner->where('name', 'like', $like)
                            ->orWhere('city', 'like', $like)
                            ->orWhere('postcode', 'like', $like)
                            ->orWhere('venue_code', 'like', $like);
                    });
                })
                ->orderByRaw("CASE WHEN approval_status = 'approved' THEN 0 WHEN approval_status = 'pending' THEN 1 ELSE 2 END")
                ->orderBy('name');

            if (! $this->shouldPaginate($request)) {
                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => 'Operation completed successfully',
                    'data' => $query->get()
                        ->map(fn (Venue $venue) => $this->venueRecordPayload($venue))
                        ->values(),
                ], 200);
            }

            $paginator = $query->paginate($this->boundedPerPage($request))->withQueryString();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => [
                        'summary' => $this->venueSummaryForMerchant($merchant),
                        'items' => $paginator->getCollection()
                            ->map(fn (Venue $venue) => $this->venueRecordPayload($venue))
                            ->values(),
                        'meta' => $this->paginationMeta($paginator),
                        'filters' => [
                            'status' => $status,
                            'category' => $category ?: 'all',
                            'search' => $search,
                        ],
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function createVenue(Request $request)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);
            $validated = $this->validateVenuePayload($request);

            $venue = $merchant->venues()->create(array_merge(
                $this->normalisedVenuePayload($validated),
                [
                    'is_active' => false,
                    'approval_status' => 'pending',
                    'submitted_for_approval_at' => now(),
                    'offer_enabled' => false,
                    'offer_value' => $this->isFoodBusiness($validated['category']) ? 5 : 5,
                    'offer_days' => ['friday', 'saturday'],
                    'offer_start_time' => '18:00:00',
                    'offer_end_time' => '23:59:00',
                    'minimum_order' => $this->isFoodBusiness($validated['category']) ? 25 : null,
                    'fulfilment_type' => $this->isFoodBusiness($validated['category']) ? 'delivery' : 'venue',
                    'offer_review_status' => 'draft',
                    'offer_type' => $this->isFoodBusiness($validated['category']) ? 'food' : 'ride',
                ]
            ));

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Venue submitted for admin approval.',
                'data' => [
                        'venue' => $this->venueRecordPayload($venue->fresh()),
                    ],
            ], 201);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function updateVenue(Request $request, Venue $venue)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);

            if ((int) $venue->merchant_id !== (int) $merchant->id) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => 'Venue does not belong to this merchant.',
                ], 403);
            }

            $validated = $this->validateVenuePayload($request);

            $locationChanged = $this->addressHasChanged($venue, [
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'postcode' => strtoupper(trim($validated['postcode'])),
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]);

            $updates = $this->normalisedVenuePayload($validated);

            if ($venue->approval_status !== 'approved' || $locationChanged) {
                $updates = array_merge($updates, [
                    'is_active' => false,
                    'approval_status' => 'pending',
                    'submitted_for_approval_at' => now(),
                    'rejected_at' => null,
                    'rejection_reason' => null,
                ]);
            }

            $venue->update($updates);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => $venue->approval_status === 'approved' && ! $locationChanged
                    ? 'Venue information updated successfully.'
                    : 'Venue updated and sent for admin approval.',
                'data' => [
                        'venue' => $this->venueRecordPayload($venue->fresh()),
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteVenue(Request $request, Venue $venue)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);

            if ((int) $venue->merchant_id !== (int) $merchant->id) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => 'Venue does not belong to this merchant.',
                ], 403);
            }

            if ($venue->vouchers()->exists()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'This venue has voucher history and cannot be deleted.',
                ], 422);
            }

            if ($venue->approval_status === 'approved') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Approved venues cannot be deleted from the merchant dashboard. Ask admin to deactivate it.',
                ], 422);
            }

            $venue->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue removed successfully.',
                'data' => [],
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function walletTransactions(Request $request)
    {
        try {
            $merchant = $this->merchantForUser($request);
            $type = strtolower((string) $request->query('type', 'all'));
            $search = trim((string) $request->query('search', $request->query('q', '')));

            $query = WalletTransaction::query()
                ->where('merchant_id', $merchant->id)
                ->when($type !== '' && $type !== 'all', function ($query) use ($type) {
                    $query->where('type', $type);
                })
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%' . $search . '%';
                    $query->where(function ($inner) use ($like) {
                        $inner->where('reference', 'like', $like)
                            ->orWhere('notes', 'like', $like)
                            ->orWhere('type', 'like', $like);
                    });
                })
                ->latest();

            if (! $this->shouldPaginate($request)) {
                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => 'Operation completed successfully',
                    'data' => $query->get(),
                ], 200);
            }

            $paginator = $query->paginate($this->boundedPerPage($request))->withQueryString();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => [
                        'summary' => $this->walletTransactionSummaryForMerchant($merchant),
                        'items' => $paginator->getCollection()->values(),
                        'meta' => $this->paginationMeta($paginator),
                        'filters' => [
                            'type' => $type,
                            'search' => $search,
                        ],
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function vouchers(Request $request)
    {
        try {
            $merchant = $this->merchantForUser($request);
            $status = strtolower((string) $request->query('status', 'all'));
            $journeyType = strtolower((string) $request->query('journey_type', 'all'));
            $search = trim((string) $request->query('search', $request->query('q', '')));

            $query = Voucher::query()
                ->with(['venue', 'user'])
                ->where('merchant_id', $merchant->id)
                ->when($status !== '' && $status !== 'all', function ($query) use ($status) {
                    $query->where('status', $status);
                })
                ->when($journeyType !== '' && $journeyType !== 'all', function ($query) use ($journeyType) {
                    $query->where('journey_type', $journeyType);
                })
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%' . $search . '%';
                    $query->where(function ($inner) use ($like) {
                        $inner->where('code', 'like', $like)
                            ->orWhere('provider_name', 'like', $like)
                            ->orWhereHas('venue', function ($venueQuery) use ($like) {
                                $venueQuery->where('name', 'like', $like)
                                    ->orWhere('postcode', 'like', $like)
                                    ->orWhere('venue_code', 'like', $like);
                            })
                            ->orWhereHas('user', function ($userQuery) use ($like) {
                                $userQuery->where('name', 'like', $like)
                                    ->orWhere('email', 'like', $like)
                                    ->orWhere('phone', 'like', $like);
                            });
                    });
                })
                ->latest();

            if (! $this->shouldPaginate($request)) {
                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => 'Operation completed successfully',
                    'data' => $query->get(),
                ], 200);
            }

            $paginator = $query->paginate($this->boundedPerPage($request))->withQueryString();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => [
                        'summary' => $this->voucherSummaryForMerchant($merchant),
                        'items' => $paginator->getCollection()->values(),
                        'meta' => $this->paginationMeta($paginator),
                        'filters' => [
                            'status' => $status,
                            'journey_type' => $journeyType,
                            'search' => $search,
                        ],
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function createVoucher(Request $request)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);
            $wallet = $merchant->wallet;
            $venue = $this->primaryVenueForMerchant($merchant);

            if (! $venue) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'No venue found for this merchant.',
                ], 422);
            }

            if (! $venue->is_active || $venue->approval_status !== 'approved') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'This venue is still waiting for admin approval.',
                    'errors' => [
                            'venue_status' => $venue->approval_status ?: 'pending',
                        ],
                ], 422);
            }

            $validated = $request->validate([
                'user_name' => ['required', 'string', 'max:255'],
                'user_email' => ['nullable', 'email', 'max:255'],
                'user_phone' => ['nullable', 'string', 'max:50'],
                'journey_type' => ['required', 'in:ride,food'],
                'voucher_value' => ['nullable', 'numeric', 'min:1', 'max:100'],
                'promo_message' => ['nullable', 'string', 'max:80'],
            ]);

            if (blank($validated['user_email'] ?? null) && blank($validated['user_phone'] ?? null)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Enter at least an email address or phone number for the customer.',
                    'errors' => [
                            'contact' => ['Email or phone is required.'],
                        ],
                ], 422);
            }

            $allowedJourneyTypes = $this->allowedJourneyTypesForVenue($venue);
            if (! in_array($validated['journey_type'], $allowedJourneyTypes, true)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'This venue offer does not support the selected voucher type.',
                    'errors' => [
                            'journey_type' => ['Selected journey type is not enabled for this merchant.'],
                        ],
                ], 422);
            }

            if (! $venue->offer_enabled) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Enable the venue offer before creating vouchers.',
                ], 422);
            }

            $voucherValue = (float) ($validated['voucher_value'] ?? $venue->offer_value ?? 5);
            $range = OfferRules::voucherRangeForBusiness($venue->category);
            if ($voucherValue < $range['min'] || $voucherValue > $range['max']) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => OfferRules::voucherAmountMessage($venue->category),
                    'errors' => [
                            'voucher_value' => [OfferRules::voucherAmountMessage($venue->category)],
                        ],
                ], 422);
            }

            $this->venueUrgencyService->guardAvailability($venue);

            $serviceFee = (float) ($merchant->default_service_fee ?? 2.50);
            $providerLink = $this->providerVoucherLinkService->matchActiveLink($venue, $validated['journey_type']);
            $rideTripType = $validated['journey_type'] === 'ride' ? ($providerLink?->ride_trip_type ?? $venue->ride_trip_type) : null;
            $totalCharge = OfferRules::requiredWalletBalanceForVoucher($voucherValue, $serviceFee, $validated['journey_type'], $rideTripType);

            if ((float) $wallet->balance < $totalCharge) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Insufficient wallet balance. Please top up before issuing a voucher.',
                    'errors' => [
                            'wallet_balance' => number_format((float) $wallet->balance, 2, '.', ''),
                            'required_balance' => number_format($totalCharge, 2, '.', ''),
                        ],
                ], 422);
            }

            $customer = $this->findOrCreateVoucherCustomer($validated, $venue->postcode);
            $promoMessage = trim((string) ($validated['promo_message'] ?? ''));
            if ($promoMessage === '') {
                $promoMessage = $this->defaultPromoMessage($venue->name, $validated['journey_type'], $rideTripType, $voucherValue);
            }

            $voucher = DB::transaction(function () use ($merchant, $venue, $customer, $validated, $voucherValue, $serviceFee, $totalCharge, $promoMessage, $providerLink, $rideTripType) {
                return Voucher::create([
                    'user_id' => $customer->id,
                    'merchant_id' => $merchant->id,
                    'venue_id' => $venue->id,
                    'provider_voucher_link_id' => $providerLink?->id,
                    'code' => 'TTC-' . strtoupper(Str::random(8)),
                    'journey_type' => $validated['journey_type'],
                    'provider_name' => $providerLink?->provider ?? ($validated['journey_type'] === 'food' ? 'ubereats' : 'uber'),
                    'offer_type' => $providerLink?->offer_type ?? $venue->offer_type,
                    'ride_trip_type' => $rideTripType,
                    'destination_postcode' => $venue->postcode,
                    'promo_message' => Str::limit($promoMessage, 80, ''),
                    'voucher_value' => $voucherValue,
                    'service_fee' => $serviceFee,
                    'total_charge' => $totalCharge,
                    'minimum_order' => $validated['journey_type'] === 'food'
                        ? ($providerLink?->minimum_order !== null ? (float) $providerLink->minimum_order : ($venue->minimum_order ?? 25))
                        : null,
                    'status' => 'issued',
                    'issued_at' => now(),
                    'expires_at' => now()->addHours(4),
                    'voucher_link_url' => $providerLink?->link_url,
                ]);
            });
            $voucher->load(['venue', 'user']);
            $this->affiliateTrackingService->markVoucherIssued($voucher->fresh(['user']));

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Voucher created successfully.',
                'data' => [
                        'voucher' => $voucher,
                        'wallet' => $this->walletPayload($merchant->fresh(['wallet'])),
                        'exact_link_used' => (bool) $voucher->voucher_link_url,
                    ],
            ], 201);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function topUp(Request $request)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);
            $wallet = $merchant->wallet;

            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:1'],
            ]);

            $minimumTopUpAmount = $this->merchantBusinessRuleService->minimumTopUpAmount();
            if ((float) $validated['amount'] < $minimumTopUpAmount) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => sprintf('Minimum top-up is £%.2f.', $minimumTopUpAmount),
                    'errors' => [
                            'amount' => [sprintf('Minimum top-up is £%.2f.', $minimumTopUpAmount)],
                        ],
                ], 422);
            }

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

            $freshMerchant = $merchant->fresh(['wallet']);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Wallet topped up successfully',
                'data' => [
                        'wallet' => $this->walletPayload($freshMerchant),
                        'alert_status' => $this->walletAlertService->statusForWallet($freshMerchant->wallet),
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function redeemVoucher(Request $request, Voucher $voucher)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);

            if ((int) $voucher->merchant_id !== (int) $merchant->id) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => 'Voucher does not belong to this merchant',
                ], 403);
            }

            $eventType = $voucher->journey_type === 'food' ? 'order_completed' : 'ride_completed';
            $result = $this->providerVerificationService->simulateEvent($voucher, $eventType, [
                'destination_match' => $voucher->journey_type === 'food' ? null : true,
            ]);

            $freshMerchant = $merchant->fresh(['wallet']);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Provider completion confirmed and wallet charged successfully.',
                'data' => [
                        'wallet' => $this->walletPayload($freshMerchant),
                        'voucher' => $result['voucher'],
                        'provider_event' => $result['event'],
                        'low_balance_alert' => $result['low_balance_alert'],
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function simulateProviderEvent(Request $request, Voucher $voucher)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);

            if ((int) $voucher->merchant_id !== (int) $merchant->id) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => 'Voucher does not belong to this merchant',
                ], 403);
            }

            if (! config('talktocas.provider_verification.allow_simulated_events', true)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => 'Simulated provider events are disabled in this environment.',
                ], 403);
            }

            $validated = $request->validate([
                'event_type' => ['required', 'in:ride_completed,order_completed,order_cancelled,destination_mismatch,ride_terminated_early'],
                'provider_reference' => ['nullable', 'string', 'max:120'],
                'destination_match' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string', 'max:500'],
            ]);

            $result = $this->providerVerificationService->simulateEvent($voucher, $validated['event_type'], $validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Provider event processed successfully.',
                'data' => [
                        'wallet' => $this->walletPayload($merchant->fresh(['wallet'])),
                        'voucher' => $result['voucher'],
                        'provider_event' => $result['event'],
                        'low_balance_alert' => $result['low_balance_alert'],
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function createStripeTopUpCheckout(Request $request)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);

            $validated = $request->validate([
                'amount' => ['required', 'numeric', 'min:1'],
                'mode' => ['nullable', 'in:manual,auto_top_up'],
            ]);

            $minimumTopUpAmount = $this->merchantBusinessRuleService->minimumTopUpAmount();
            if ((float) $validated['amount'] < $minimumTopUpAmount) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => sprintf('Minimum top-up is £%.2f.', $minimumTopUpAmount),
                    'errors' => [
                            'amount' => [sprintf('Minimum top-up is £%.2f.', $minimumTopUpAmount)],
                        ],
                ], 422);
            }

            $intent = $this->stripeFinanceService->createTopUpIntent(
                $merchant,
                (float) $validated['amount'],
                (string) ($validated['mode'] ?? 'manual')
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Stripe top-up checkout created successfully.',
                'data' => [
                        'intent' => $this->stripeFinanceService->topUpIntentPayload($intent),
                        'stripe_finance' => $this->stripeFinanceService->merchantPayload($merchant->fresh(['wallet'])),
                    ],
            ], 201);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function simulateStripeTopUpSuccess(Request $request, string $checkoutCode)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);

            $result = $this->stripeFinanceService->simulateTopUpSuccess($merchant, $checkoutCode);
            $freshMerchant = $merchant->fresh(['wallet']);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => ($result['already_processed'] ?? false) ? 'Stripe top-up was already confirmed earlier.' : 'Stripe top-up confirmed successfully on localhost.',
                'data' => [
                        'intent' => $this->stripeFinanceService->topUpIntentPayload($result['intent']),
                        'wallet' => $this->walletPayload($freshMerchant),
                        'wallet_status' => $this->walletAlertService->statusForWallet($freshMerchant->wallet),
                        'stripe_finance' => $this->stripeFinanceService->merchantPayload($freshMerchant),
                        'already_processed' => $result['already_processed'] ?? false,
                    ],
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function sendTestLowBalanceAlert(Request $request)
    {
        try {
            DB::beginTransaction();

            $merchant = $this->merchantForUser($request);
            $result = $this->walletAlertService->sendTestAlert($merchant);

            if (! $result['sent']) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'No email or WhatsApp destination is configured for this merchant.',
                    'errors' => [
                            'channels' => ['Add a contact email or WhatsApp number before sending alerts.'],
                        ],
                ], 422);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Wallet alert test sent successfully.',
                'data' => $result,
            ], 200);
        
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    private function shouldPaginate(Request $request): bool
    {
        if ($request->boolean('paginated')) {
            return true;
        }

        foreach (['page', 'per_page', 'status', 'category', 'journey_type', 'type', 'search', 'q'] as $key) {
            if ($request->has($key)) {
                return true;
            }
        }

        return false;
    }

    private function boundedPerPage(Request $request): int
    {
        return max(5, min(50, (int) $request->query('per_page', 10)));
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    private function venueSummaryForMerchant(Merchant $merchant): array
    {
        return [
            'total' => $merchant->venues()->count(),
            'approved' => $merchant->venues()->where('approval_status', 'approved')->count(),
            'pending' => $merchant->venues()->where('approval_status', 'pending')->count(),
            'rejected' => $merchant->venues()->where('approval_status', 'rejected')->count(),
            'active' => $merchant->venues()->where('is_active', true)->count(),
        ];
    }

    private function voucherSummaryForMerchant(Merchant $merchant): array
    {
        return [
            'total' => $merchant->vouchers()->count(),
            'issued' => $merchant->vouchers()->where('status', 'issued')->count(),
            'redeemed' => $merchant->vouchers()->where('status', 'redeemed')->count(),
            'expired' => $merchant->vouchers()->where('status', 'expired')->count(),
            'ride' => $merchant->vouchers()->where('journey_type', 'ride')->count(),
            'food' => $merchant->vouchers()->where('journey_type', 'food')->count(),
        ];
    }

    private function walletTransactionSummaryForMerchant(Merchant $merchant): array
    {
        return [
            'total' => WalletTransaction::where('merchant_id', $merchant->id)->count(),
            'deposit' => WalletTransaction::where('merchant_id', $merchant->id)->where('type', 'deposit')->count(),
            'voucher_charge' => WalletTransaction::where('merchant_id', $merchant->id)->where('type', 'voucher_charge')->count(),
            'refund' => WalletTransaction::where('merchant_id', $merchant->id)->where('type', 'refund')->count(),
        ];
    }
    private function validateVenuePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:club,bar,restaurant,takeaway,cafe'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['required', 'string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:1200'],
            'promo_message' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function normalisedVenuePayload(array $validated): array
    {
        return [
            'name' => trim($validated['name']),
            'category' => $validated['category'],
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'postcode' => strtoupper(trim($validated['postcode'])),
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'description' => $validated['description'] ?? null,
            'promo_message' => $validated['promo_message'] ?? null,
        ];
    }

    private function venueRecordPayload(Venue $venue): array
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
            'is_active' => (bool) $venue->is_active,
            'approval_status' => $venue->approval_status ?: ((bool) $venue->is_active ? 'approved' : 'pending'),
            'venue_code' => $venue->venue_code,
            'submitted_for_approval_at' => optional($venue->submitted_for_approval_at)?->toIso8601String(),
            'approved_at' => optional($venue->approved_at)?->toIso8601String(),
            'rejected_at' => optional($venue->rejected_at)?->toIso8601String(),
            'rejection_reason' => $venue->rejection_reason,
            'offer_enabled' => (bool) $venue->offer_enabled,
            'offer_value' => $venue->offer_value !== null ? number_format((float) $venue->offer_value, 2, '.', '') : null,
            'offer_type' => $venue->offer_type,
            'ride_trip_type' => $venue->ride_trip_type,
        ];
    }

    private function merchantForUser(Request $request): Merchant
    {
        return Merchant::with(['wallet', 'venues'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function primaryVenueForMerchant(Merchant $merchant)
    {
        return $merchant->venues()
            ->orderByRaw("CASE WHEN approval_status = 'approved' THEN 0 WHEN approval_status = 'pending' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->first();
    }

    private function isFoodBusiness(?string $businessType): bool
    {
        return OfferRules::isFoodBusiness($businessType);
    }

    private function allowedJourneyTypesForVenue($venue): array
    {
        return match ($venue->offer_type) {
            'food' => ['food'],
            'dual_choice' => ['ride', 'food'],
            default => ['ride'],
        };
    }

    private function findOrCreateVoucherCustomer(array $validated, string $postcode): User
    {
        $email = filled($validated['user_email'] ?? null) ? strtolower(trim((string) ($validated['user_email'] ?? ''))) : null;
        $phone = filled($validated['user_phone'] ?? null) ? trim((string) ($validated['user_phone'] ?? '')) : null;

        $user = null;
        if ($email) {
            $user = User::query()->where('email', $email)->first();
        }

        if (! $user && $phone) {
            $user = User::query()->where('phone', $phone)->first();
        }

        if ($user) {
            $user->update([
                'name' => $validated['user_name'],
                'email' => $email ?? $user->email,
                'phone' => $phone ?? $user->phone,
                'postcode' => $user->postcode ?: $postcode,
                'is_active' => true,
                'email_verified_at' => $email ? ($user->email_verified_at ?? now()) : $user->email_verified_at,
                'phone_verified_at' => $phone ? ($user->phone_verified_at ?? now()) : $user->phone_verified_at,
            ]);

            UserRole::query()->firstOrCreate(
                ['user_id' => $user->id, 'role' => 'user'],
                ['assigned_at' => now()]
            );

            return $user->fresh();
        }

        $user = User::create([
            'name' => $validated['user_name'],
            'email' => $email ?: 'guest+' . strtolower(Str::random(10)) . '@talktocas.local',
            'password' => Str::random(32),
            'role' => 'user',
            'phone' => $phone,
            'postcode' => $postcode,
            'is_active' => true,
            'email_verified_at' => $email ? now() : null,
            'phone_verified_at' => $phone ? now() : null,
        ]);

        UserRole::query()->firstOrCreate(
            ['user_id' => $user->id, 'role' => 'user'],
            ['assigned_at' => now()]
        );

        return $user;
    }

    private function defaultPromoMessage(string $venueName, string $journeyType, ?string $rideTripType, float $voucherValue): string
    {
        if ($journeyType === 'food') {
            return sprintf('£%.0f off your food order at %s. Use it before it expires.', $voucherValue, $venueName);
        }

        return sprintf(
            '£%.0f off your ride to %s. %s.',
            $voucherValue,
            $venueName,
            OfferRules::rideTripTypeLabel($rideTripType)
        );
    }

    private function offerSyncChangedFields(array $before, array $after): array
    {
        $keys = [
            'merchant.business_type',
            'venue.offer_enabled',
            'venue.offer_value',
            'venue.offer_days',
            'venue.offer_start_time',
            'venue.offer_end_time',
            'venue.minimum_order',
            'venue.fulfilment_type',
            'venue.offer_type',
            'venue.ride_trip_type',
        ];

        $changed = [];
        foreach ($keys as $key) {
            if (Arr::get($before, $key) !== Arr::get($after, $key)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    private function addressHasChanged($venue, array $next): bool
    {
        return trim((string) ($venue->address ?? '')) !== trim((string) ($next['address'] ?? ''))
            || trim((string) ($venue->city ?? '')) !== trim((string) ($next['city'] ?? ''))
            || strtoupper(trim((string) ($venue->postcode ?? ''))) !== strtoupper(trim((string) ($next['postcode'] ?? '')))
            || (float) ($venue->latitude ?? 0) !== (float) ($next['latitude'] ?? 0)
            || (float) ($venue->longitude ?? 0) !== (float) ($next['longitude'] ?? 0);
    }

    private function venuePayload(Merchant $merchant, $venue): array
    {
        return [
            'merchant' => [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
                'business_type' => $merchant->business_type,
                'joined_at' => optional($merchant->created_at)?->toDateTimeString(),
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
                'approval_status' => $venue->approval_status ?: ((bool) $venue->is_active ? 'approved' : 'pending'),
                'venue_code' => $venue->venue_code,
                'submitted_for_approval_at' => optional($venue->submitted_for_approval_at)?->toIso8601String(),
                'approved_at' => optional($venue->approved_at)?->toIso8601String(),
                'rejection_reason' => $venue->rejection_reason,
            ],
            'address_change' => $this->addressApprovalService->merchantPayload($venue),
        ];
    }

    private function offerPayload(Merchant $merchant, $venue): array
    {
        $range = OfferRules::voucherRangeForBusiness($merchant->business_type);
        $offerType = $venue->offer_type ?: ($this->isFoodBusiness($merchant->business_type) ? 'food' : 'ride');
        $serviceFee = (float) ($merchant->default_service_fee ?? 2.50);
        $rideTripType = $venue->ride_trip_type;
        $twoTripMinimum = OfferRules::requiredWalletBalanceForVoucher(
            (float) ($venue->offer_value ?? $range['default']),
            $serviceFee,
            'ride',
            'to_and_from'
        );

        return [
            'merchant' => [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
                'business_type' => $merchant->business_type,
                'joined_at' => optional($merchant->created_at)?->toDateTimeString(),
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
                'approval_status' => $venue->approval_status ?: ((bool) $venue->is_active ? 'approved' : 'pending'),
                'venue_code' => $venue->venue_code,
            ],
            'offer' => [
                'offer_enabled' => (bool) $venue->offer_enabled,
                'voucher_amount' => number_format((float) ($venue->offer_value ?? $range['default']), 2, '.', ''),
                'offer_days' => Arr::wrap($venue->offer_days ?: ['friday', 'saturday']),
                'start_time' => $venue->offer_start_time ? substr((string) $venue->offer_start_time, 0, 5) : null,
                'end_time' => $venue->offer_end_time ? substr((string) $venue->offer_end_time, 0, 5) : null,
                'minimum_order' => $venue->minimum_order !== null ? number_format((float) $venue->minimum_order, 2, '.', '') : null,
                'fulfilment_type' => $venue->fulfilment_type ?: 'venue',
                'offer_type' => $offerType,
                'ride_trip_type' => $rideTripType,
                'ride_trip_type_label' => OfferRules::offerTypeSupportsRide($offerType)
                    ? OfferRules::rideTripTypeLabel($rideTripType)
                    : null,
                'review_status' => $venue->offer_review_status ?: 'live',
                'urgency_enabled' => (bool) ($venue->urgency_enabled ?? config('talktocas.urgency.enabled', true)),
                'daily_voucher_cap' => (bool) ($venue->urgency_enabled ?? config('talktocas.urgency.enabled', true))
                    ? (string) (($venue->daily_voucher_cap ?: config('talktocas.urgency.default_daily_cap', 5)) ?: '')
                    : null,
            ],
            'wallet' => $this->walletPayload($merchant),
            'urgency_status' => $this->venueUrgencyService->summaryForVenue($venue),
            'offer_rules' => [
                'voucher_amount' => [
                    'min' => $range['min'],
                    'max' => $range['max'],
                    'default_value' => $range['default'],
                    'label' => $range['label'],
                ],
                'minimum_order_default' => OfferRules::offerTypeSupportsFood($offerType) ? 25 : null,
                'minimum_order_required' => OfferRules::offerTypeSupportsFood($offerType),
                'offer_types' => OfferRules::offerTypes(),
                'ride_trip_types' => OfferRules::rideTripTypes(),
                'minimum_balance_for_two_trips' => number_format($twoTripMinimum, 2, '.', ''),
                'minimum_top_up_amount' => number_format($this->merchantBusinessRuleService->minimumTopUpAmount(), 2, '.', ''),
            ],
            'offer_sync' => $this->offerSyncService->merchantPayload($merchant, $venue),
            'address_change' => $this->addressApprovalService->merchantPayload($venue),
            'exact_voucher_links' => $this->providerVoucherLinkService->merchantSummary($merchant, $venue),
        ];
    }

    private function walletPayload(Merchant $merchant): array
    {
        $wallet = $merchant->wallet;

        return [
            'balance' => number_format((float) ($wallet->balance ?? 0), 2, '.', ''),
            'low_balance_threshold' => number_format((float) ($wallet->low_balance_threshold ?? 0), 2, '.', ''),
            'auto_top_up_enabled' => (bool) ($wallet->auto_top_up_enabled ?? false),
            'auto_top_up_amount' => number_format((float) ($wallet->auto_top_up_amount ?? 0), 2, '.', ''),
            'minimum_top_up_amount' => number_format($this->merchantBusinessRuleService->minimumTopUpAmount(), 2, '.', ''),
            'last_alert_at' => optional($wallet->last_alert_at)?->toIso8601String(),
            'alert_status' => $this->walletAlertService->statusForWallet($wallet),
        ];
    }

    private function merchantBusinessRulesPayload(Merchant $merchant): array
    {
        return [
            'onboarding_plan' => $merchant->onboarding_plan,
            'free_trial_status' => $merchant->free_trial_status,
            'free_trial_message' => $merchant->free_trial_message,
            'free_trial_ineligible_reason' => $merchant->free_trial_ineligible_reason,
            'blocked_keywords' => $merchant->trial_blocked_keywords ?? [],
            'minimum_top_up_amount' => number_format($this->merchantBusinessRuleService->minimumTopUpAmount(), 2, '.', ''),
        ];
    }
}
