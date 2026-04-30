<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\ApproveVenueRequest;
use App\Http\Requests\Admin\ListMerchantsRequest;
use App\Http\Requests\Admin\ListVenuesRequest;
use App\Http\Requests\Admin\RejectVenueRequest;
use App\Http\Requests\Admin\UpdateMerchantRequest;
use App\Http\Requests\Admin\UpdateVenueRequest;
use App\Mail\MerchantApprovedMail;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Venue;
use App\Models\Voucher;
use App\Models\WalletTransaction;
use App\Services\Fraud\FraudPreventionService;
use App\Services\Merchant\MerchantOfferSyncService;
use App\Services\Merchant\VenueAddressApprovalService;
use App\Services\Notifications\AreaLaunchAlertService;
use App\Services\Operations\IntegrationReadinessService;
use App\Services\Voucher\ProviderVoucherLinkService;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AdminDashboardController extends BaseController
{
    public function __construct(
        private readonly FraudPreventionService $fraudPreventionService,
        private readonly IntegrationReadinessService $integrationReadinessService,
        private readonly MerchantOfferSyncService $offerSyncService,
        private readonly VenueAddressApprovalService $addressApprovalService,
        private readonly ProviderVoucherLinkService $providerVoucherLinkService,
        private readonly WhatsAppTemplateService $whatsAppTemplateService,
        private readonly AreaLaunchAlertService $areaLaunchAlertService,
    ) {
    }

    public function dashboard()
    {
        try {
            $pendingMerchants = Merchant::with(['user', 'wallet', 'venues.merchant.user'])
                ->whereHas('user', fn ($query) => $query->where('is_active', false))
                ->latest()
                ->take(10)
                ->get();

            $pendingVenues = Venue::with(['merchant.user'])
                ->where('approval_status', 'pending')
                ->latest('submitted_for_approval_at')
                ->take(10)
                ->get();

            $fraudSummary = $this->fraudPreventionService->dashboardSummary();
            $whatsAppDashboard = $this->whatsAppTemplateService->dashboardPayload();

            $totalUsers = User::count();
            $totalMerchants = Merchant::count();
            $totalVenues = Venue::count();
            $pendingVenuesCount = Venue::where('approval_status', 'pending')->count();
            $approvedVenuesCount = Venue::where('approval_status', 'approved')->count();
            $issuedVouchersCount = Voucher::where('status', 'issued')->count();
            $redeemedVouchersCount = Voucher::where('status', 'redeemed')->count();
            $walletDebitsTotal = (float) WalletTransaction::where('type', 'debit')->sum('amount');

            $lowBalanceMerchants = Merchant::with(['user', 'wallet', 'venues.merchant.user'])
                ->whereHas('user', fn ($query) => $query->where('is_active', true))
                ->get()
                ->filter(fn ($merchant) => $merchant->wallet && $merchant->wallet->balance < $merchant->wallet->low_balance_threshold)
                ->map(fn (Merchant $merchant) => $this->transformMerchant($merchant))
                ->values();

            $recentTransactions = WalletTransaction::with(['merchant', 'voucher'])
                ->latest()
                ->take(15)
                ->get();

            $pendingMerchantsTransformed = $pendingMerchants->map(fn (Merchant $merchant) => $this->transformMerchant($merchant))->values();
            $pendingVenuesTransformed = $pendingVenues->map(fn (Venue $venue) => $this->transformVenue($venue))->values();

            $integrationReadiness = $this->integrationReadinessService->dashboardPayload();
            $offerSync = $this->offerSyncService->adminDashboardPayload();
            $addressChanges = $this->addressApprovalService->adminDashboardPayload();
            $providerVoucherLinkSummary = $this->providerVoucherLinkService->summary();
            $providerVoucherLinkItems = $this->providerVoucherLinkService->listPayloads(8);
            $whatsAppTemplatePreview = $this->whatsAppTemplateService->approvedTemplatePreview();
            $areaLaunchAlerts = $this->areaLaunchAlertService->dashboardPayload((int) config('talktocas.area_launch_alerts.dashboard_recent_limit', 8));

            $data = [
                    'stats' => [
                        'total_users' => $totalUsers,
                        'total_merchants' => $totalMerchants,
                        'total_venues' => $totalVenues,
                        'pending_venues' => $pendingVenuesCount,
                        'approved_venues' => $approvedVenuesCount,
                        'issued_vouchers' => $issuedVouchersCount,
                        'redeemed_vouchers' => $redeemedVouchersCount,
                        'wallet_debits_total' => $walletDebitsTotal,
                        'pending_merchants' => $pendingMerchants->count(),
                        'flagged_users' => $fraudSummary['flagged_users'],
                        'blocked_users' => $fraudSummary['blocked_users'],
                    ],
                    'fraud_summary' => $fraudSummary,
                    'low_balance_merchants' => $lowBalanceMerchants,
                    'recent_transactions' => $recentTransactions,
                    'pending_merchants' => $pendingMerchantsTransformed,
                    'pending_venues' => $pendingVenuesTransformed,
                    'integration_readiness' => $integrationReadiness,
                    'offer_sync' => $offerSync,
                    'address_changes' => $addressChanges,
                    'provider_voucher_links' => [
                        'summary' => $providerVoucherLinkSummary,
                        'items' => $providerVoucherLinkItems,
                    ],
                    'whatsapp_templates' => [
                        'provider' => $whatsAppDashboard['provider'] ?? [],
                        'summary' => $whatsAppDashboard['summary'] ?? [],
                        'approved_template_keys' => $whatsAppDashboard['approved_template_keys'] ?? [],
                        'preview' => $whatsAppTemplatePreview,
                    ],
                    'area_launch_alerts' => $areaLaunchAlerts,
                ];

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function merchants(ListMerchantsRequest $request)
    {
        try {
            $validated = $request->validated();

            $query = Merchant::with(['user', 'wallet', 'venues.merchant.user'])->withCount('venues');

            $status = $validated['status'] ?? 'all';
            if ($status !== 'all') {
                if ($status === 'pending') {
                    $query->where(function ($inner) {
                        $inner->whereIn('status', ['pending', 'inactive'])
                            ->orWhereHas('user', fn ($userQuery) => $userQuery->where('is_active', false));
                    });
                } elseif ($status === 'approved' || $status === 'active') {
                    $query->where('status', 'active')
                        ->whereHas('user', fn ($userQuery) => $userQuery->where('is_active', true));
                } else {
                    $query->where('status', $status);
                }
            }

            if (($search = trim((string) ($validated['search'] ?? ''))) !== '') {
                $query->where(function ($inner) use ($search) {
                    $inner->where('business_name', 'like', "%{$search}%")
                        ->orWhere('business_type', 'like', "%{$search}%")
                        ->orWhere('contact_email', 'like', "%{$search}%")
                        ->orWhere('contact_phone', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            if ($request->has('page') || $request->has('per_page')) {
                $perPage = (int) ($validated['per_page'] ?? 25);
                $paginated = $query->orderBy('business_name')->paginate($perPage);
                $merchantSummary = $this->merchantSummary();
                $merchantItems = $paginated->getCollection()->map(fn (Merchant $merchant) => $this->transformMerchant($merchant))->values();

                $data = [
                        'summary' => $merchantSummary,
                        'items' => $merchantItems,
                        'pagination' => [
                            'current_page' => $paginated->currentPage(),
                            'per_page' => $paginated->perPage(),
                            'total' => $paginated->total(),
                            'last_page' => $paginated->lastPage(),
                        ],
                    ];

                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => 'Operation completed successfully',
                    'data' => $data,
                ], 200);
            }

            $merchantItems = $query->orderBy('business_name')->get()->map(
                    fn (Merchant $merchant) => $this->transformMerchant($merchant)
                )->values();
            $data = $merchantItems;

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function venues(ListVenuesRequest $request)
    {
        try {
            $validated = $request->validated();

            $status = $validated['status'] ?? 'pending';
            $limit = (int) ($validated['limit'] ?? 50);
            $query = Venue::with(['merchant.user', 'approvedBy']);

            if ($status !== 'all') {
                $query->where('approval_status', $status);
            }

            if ($validated['merchant_id'] ?? null) {
                $query->where('merchant_id', (int) $validated['merchant_id']);
            }

            if (($category = trim((string) ($validated['category'] ?? ''))) !== '') {
                $query->where('category', 'like', "%{$category}%");
            }

            if (filter_var($validated['active_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $query->where('is_active', true);
            }

            if (($search = trim((string) ($validated['search'] ?? ''))) !== '') {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('postcode', 'like', "%{$search}%")
                        ->orWhere('venue_code', 'like', "%{$search}%")
                        ->orWhereHas('merchant', fn ($merchantQuery) => $merchantQuery->where('business_name', 'like', "%{$search}%"));
                });
            }

            $pendingVenuesCount = Venue::where('approval_status', 'pending')->count();
            $approvedVenuesCount = Venue::where('approval_status', 'approved')->count();
            $rejectedVenuesCount = Venue::where('approval_status', 'rejected')->count();
            $totalVenuesCount = Venue::count();

            $summary = [
                'pending' => $pendingVenuesCount,
                'approved' => $approvedVenuesCount,
                'rejected' => $rejectedVenuesCount,
                'total' => $totalVenuesCount,
            ];

            if ($request->has('page') || $request->has('per_page')) {
                $perPage = (int) ($validated['per_page'] ?? 25);
                $paginated = $query->latest('submitted_for_approval_at')->paginate($perPage);
                $venueItems = $paginated->getCollection()->map(fn (Venue $venue) => $this->transformVenue($venue))->values();

                $data = [
                        'summary' => $summary,
                        'items' => $venueItems,
                        'pagination' => [
                            'current_page' => $paginated->currentPage(),
                            'per_page' => $paginated->perPage(),
                            'total' => $paginated->total(),
                            'last_page' => $paginated->lastPage(),
                        ],
                    ];

                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => 'Operation completed successfully',
                    'data' => $data,
                ], 200);
            }

            $venueItems = $query
                ->latest('submitted_for_approval_at')
                ->limit($limit)
                ->get()
                ->map(fn (Venue $venue) => $this->transformVenue($venue))
                ->values();

            $data = [
                    'summary' => $summary,
                    'items' => $venueItems,
                ];

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function pendingMerchants()
    {
        try {
            $merchantItems = Merchant::with(['user', 'wallet', 'venues.merchant.user'])
                ->whereHas('user', fn ($query) => $query->where('is_active', false))
                ->latest()
                ->get()
                ->map(fn (Merchant $merchant) => $this->transformMerchant($merchant))
                ->values();
            $data = $merchantItems;

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function approveMerchant(Request $request, Merchant $merchant)
    {
        try {
            DB::beginTransaction();

            $merchant->update(['status' => 'active']);
            $merchant->user?->update(['is_active' => true]);

            if ($merchant->contact_email) {
                try {
                    Mail::to($merchant->contact_email)->send(new MerchantApprovedMail($merchant));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $areaLaunchAlert = null;
            if ((bool) config('talktocas.area_launch_alerts.enabled', true) && (bool) config('talktocas.area_launch_alerts.automatic_on_merchant_approval', true)) {
                $areaLaunchAlert = $this->areaLaunchAlertService->triggerForMerchant(
                    $merchant->fresh(['user', 'wallet', 'venues.merchant.user']),
                    $request->user(),
                    'merchant_approval',
                    'Automatic area launch alert after merchant approval.'
                );
            }

            $merchantData = $this->transformMerchant($merchant->load(['user', 'wallet', 'venues.merchant.user']));

            $data = [
                    'merchant' => $merchantData,
                    'area_launch_alert' => $areaLaunchAlert,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Merchant approved successfully. Review pending venues separately to save manual venue codes.',
                'data' => $data,
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

    public function rejectMerchant(Request $request, Merchant $merchant)
    {
        try {
            DB::beginTransaction();

            $merchant->update(['status' => 'rejected']);
            $merchant->user?->update(['is_active' => false]);
            $merchant->venues()->where('approval_status', 'pending')->update(['is_active' => false]);

            $merchantData = $this->transformMerchant($merchant->load(['user', 'wallet', 'venues.merchant.user']));

            $data = [
                    'merchant' => $merchantData,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Merchant rejected.',
                'data' => $data,
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

    public function updateMerchant(UpdateMerchantRequest $request, Merchant $merchant)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $merchantData = collect($validated)
                ->only(['business_name', 'business_type', 'contact_email', 'contact_phone', 'whatsapp_number', 'default_service_fee', 'status'])
                ->filter(fn ($value) => $value !== null)
                ->all();

            if ($merchantData !== []) {
                $merchant->update($merchantData);
            }

            if ($merchant->user) {
                $userData = [];
                if (array_key_exists('user_name', $validated) && $validated['user_name'] !== null) {
                    $userData['name'] = $validated['user_name'];
                }
                if (array_key_exists('user_email', $validated) && $validated['user_email'] !== null) {
                    $userData['email'] = $validated['user_email'];
                }
                if (array_key_exists('user_is_active', $validated)) {
                    $userData['is_active'] = (bool) $validated['user_is_active'];
                } elseif (($merchantData['status'] ?? null) === 'active') {
                    $userData['is_active'] = true;
                } elseif (in_array($merchantData['status'] ?? null, ['inactive', 'rejected'], true)) {
                    $userData['is_active'] = false;
                }

                if ($userData !== []) {
                    $merchant->user->update($userData);
                }
            }

            if ($merchant->wallet && array_key_exists('wallet_low_balance_threshold', $validated) && $validated['wallet_low_balance_threshold'] !== null) {
                $merchant->wallet->update(['low_balance_threshold' => $validated['wallet_low_balance_threshold']]);
            }

            $data = [
                    'merchant' => $this->transformMerchant($merchant->fresh(['user', 'wallet', 'venues.merchant.user'])),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Merchant updated successfully.',
                'data' => $data,
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

    public function deleteMerchant(Merchant $merchant)
    {
        try {
            DB::beginTransaction();

            $user = $merchant->user;
            $merchantName = $merchant->business_name;
            $merchant->delete();

            if ($user) {
                $user->update(['is_active' => false]);
            }

            $data = [
                    'deleted' => true,
                    'merchant_name' => $merchantName,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Merchant deleted successfully. Related merchant records were removed by database cascade rules.',
                'data' => $data,
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

    public function approveVenue(ApproveVenueRequest $request, Venue $venue)
    {
        try {
            DB::beginTransaction();

            $venue->load('merchant.user');

            if ($venue->merchant?->status !== 'active' || ! $venue->merchant?->user?->is_active) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Approve the merchant account before approving venues.',
                ], 422);
            }

            $validated = $request->validated();

            $venue->update([
                'approval_status' => 'approved',
                'venue_code' => strtoupper(trim($validated['venue_code'])),
                'is_active' => true,
                'approved_at' => now(),
                'approved_by_user_id' => $request->user()->id,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            $venueData = $this->transformVenue($venue->fresh(['merchant.user']));

            $data = [
                    'venue' => $venueData,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue approved with manual 6 character alphanumeric code.',
                'data' => $data,
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

    public function rejectVenue(RejectVenueRequest $request, Venue $venue)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $venue->update([
                'approval_status' => 'rejected',
                'is_active' => false,
                'rejected_at' => now(),
                'rejection_reason' => $validated['reason'] ?? null,
            ]);

            $venueData = $this->transformVenue($venue->fresh(['merchant.user']));

            $data = [
                    'venue' => $venueData,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue rejected successfully.',
                'data' => $data,
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

    public function updateVenue(UpdateVenueRequest $request, Venue $venue)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            if (($validated['approval_status'] ?? null) === 'approved') {
                if (empty($validated['venue_code']) && empty($venue->venue_code)) {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }

                    return response()->json([
                        'success' => false,
                        'status_code' => 422,
                        'message' => 'Approved venues need a manual 6 character alphanumeric venue code.',
                    ], 422);
                }

                $validated['is_active'] = $validated['is_active'] ?? true;
                $validated['approved_at'] = now();
                $validated['approved_by_user_id'] = $request->user()->id;
                $validated['rejected_at'] = null;
                $validated['rejection_reason'] = null;
            }

            if (($validated['approval_status'] ?? null) === 'rejected') {
                $validated['is_active'] = false;
                $validated['rejected_at'] = now();
            }

            if (array_key_exists('venue_code', $validated) && $validated['venue_code'] !== null) {
                $validated['venue_code'] = strtoupper(trim($validated['venue_code']));
            }

            $venue->update($validated);

            $venueData = $this->transformVenue($venue->fresh(['merchant.user', 'approvedBy']));

            $data = [
                    'venue' => $venueData,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue updated successfully.',
                'data' => $data,
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

    public function deleteVenue(Venue $venue)
    {
        try {
            DB::beginTransaction();

            $venueName = $venue->name;
            $venue->delete();

            $data = [
                    'deleted' => true,
                    'venue_name' => $venueName,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue deleted successfully. Related venue records were removed by database cascade rules.',
                'data' => $data,
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

    private function transformMerchant(Merchant $merchant): array
    {
        return [
            'id' => $merchant->id,
            'business_name' => $merchant->business_name,
            'business_type' => $merchant->business_type,
            'status' => $merchant->status,
            'contact_email' => $merchant->contact_email,
            'contact_phone' => $merchant->contact_phone,
            'whatsapp_number' => $merchant->whatsapp_number,
            'default_service_fee' => $merchant->default_service_fee,
            'created_at' => optional($merchant->created_at)?->toIso8601String(),
            'updated_at' => optional($merchant->updated_at)?->toIso8601String(),
            'user_is_active' => (bool) ($merchant->user?->is_active ?? false),
            'is_approved' => $merchant->status === 'active' && (bool) ($merchant->user?->is_active ?? false),
            'user' => $merchant->user ? [
                'id' => $merchant->user->id,
                'name' => $merchant->user->name,
                'email' => $merchant->user->email,
                'is_active' => (bool) $merchant->user->is_active,
            ] : null,
            'wallet' => $merchant->wallet ? [
                'id' => $merchant->wallet->id,
                'balance' => $merchant->wallet->balance,
                'currency' => $merchant->wallet->currency,
                'low_balance_threshold' => $merchant->wallet->low_balance_threshold,
                'auto_top_up_enabled' => (bool) $merchant->wallet->auto_top_up_enabled,
                'auto_top_up_amount' => $merchant->wallet->auto_top_up_amount,
                'auto_top_up_minimum_balance' => $merchant->wallet->auto_top_up_minimum_balance,
                'is_trial' => (bool) $merchant->wallet->is_trial,
                'trial_credits_remaining' => $merchant->wallet->trial_credits_remaining,
            ] : null,
            'venues' => $merchant->venues->map(fn (Venue $venue) => $this->transformVenue($venue))->values(),
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
            'is_active' => (bool) $venue->is_active,
            'approval_status' => $venue->approval_status ?: ((bool) $venue->is_active ? 'approved' : 'pending'),
            'venue_code' => $venue->venue_code,
            'submitted_for_approval_at' => optional($venue->submitted_for_approval_at)?->toIso8601String(),
            'approved_at' => optional($venue->approved_at)?->toIso8601String(),
            'rejected_at' => optional($venue->rejected_at)?->toIso8601String(),
            'rejection_reason' => $venue->rejection_reason,
            'created_at' => optional($venue->created_at)?->toIso8601String(),
            'updated_at' => optional($venue->updated_at)?->toIso8601String(),
            'offer_enabled' => (bool) $venue->offer_enabled,
            'offer_value' => $venue->offer_value,
            'offer_days' => $venue->offer_days,
            'offer_start_time' => $venue->offer_start_time,
            'offer_end_time' => $venue->offer_end_time,
            'minimum_order' => $venue->minimum_order,
            'fulfilment_type' => $venue->fulfilment_type,
            'offer_review_status' => $venue->offer_review_status,
            'urgency_enabled' => (bool) $venue->urgency_enabled,
            'daily_voucher_cap' => $venue->daily_voucher_cap,
            'offer_type' => $venue->offer_type,
            'ride_trip_type' => $venue->ride_trip_type,
            'promo_message' => $venue->promo_message,
            'merchant' => $venue->merchant ? [
                'id' => $venue->merchant->id,
                'business_name' => $venue->merchant->business_name,
                'status' => $venue->merchant->status,
                'contact_email' => $venue->merchant->contact_email,
                'user_is_active' => (bool) ($venue->merchant->user?->is_active ?? false),
                'is_approved' => $venue->merchant->status === 'active' && (bool) ($venue->merchant->user?->is_active ?? false),
                'user' => $venue->merchant->user ? [
                    'id' => $venue->merchant->user->id,
                    'name' => $venue->merchant->user->name,
                    'email' => $venue->merchant->user->email,
                    'is_active' => (bool) $venue->merchant->user->is_active,
                ] : null,
            ] : null,
            'approved_by' => $this->transformApprover($venue),
        ];
    }

    private function merchantSummary(): array
    {
        return [
            'total' => Merchant::count(),
            'pending' => Merchant::where(function ($query) {
                $query->whereIn('status', ['pending', 'inactive'])
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('is_active', false));
            })->count(),
            'approved' => Merchant::where('status', 'active')->whereHas('user', fn ($userQuery) => $userQuery->where('is_active', true))->count(),
            'active' => Merchant::where('status', 'active')->count(),
            'inactive' => Merchant::where('status', 'inactive')->count(),
            'rejected' => Merchant::where('status', 'rejected')->count(),
        ];
    }

    private function normalizeBooleanInput(Request $request, string $key): void
    {
        if (! $request->has($key)) {
            return;
        }

        $value = $request->input($key);

        if (is_bool($value)) {
            return;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($normalized !== null) {
            $request->merge([
                $key => $normalized,
            ]);
        }
    }

    private function transformApprover(Venue $venue): ?array
    {
        if (! $venue->approved_by_user_id) {
            return null;
        }

        $approver = $venue->relationLoaded('approvedBy')
            ? $venue->getRelation('approvedBy')
            : User::query()->select(['id', 'name'])->find($venue->approved_by_user_id);

        return $approver ? [
            'id' => $approver->id,
            'name' => $approver->name,
        ] : null;
    }
}
