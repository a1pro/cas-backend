<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

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

        return $this->success([
            'stats' => [
                'total_users' => User::count(),
                'total_merchants' => Merchant::count(),
                'total_venues' => Venue::count(),
                'pending_venues' => Venue::where('approval_status', 'pending')->count(),
                'approved_venues' => Venue::where('approval_status', 'approved')->count(),
                'issued_vouchers' => Voucher::where('status', 'issued')->count(),
                'redeemed_vouchers' => Voucher::where('status', 'redeemed')->count(),
                'wallet_debits_total' => (float) WalletTransaction::where('type', 'debit')->sum('amount'),
                'pending_merchants' => $pendingMerchants->count(),
                'flagged_users' => $fraudSummary['flagged_users'],
                'blocked_users' => $fraudSummary['blocked_users'],
            ],
            'fraud_summary' => $fraudSummary,
            'low_balance_merchants' => Merchant::with(['user', 'wallet', 'venues.merchant.user'])
                ->whereHas('user', fn ($query) => $query->where('is_active', true))
                ->get()
                ->filter(fn ($merchant) => $merchant->wallet && $merchant->wallet->balance < $merchant->wallet->low_balance_threshold)
                ->map(fn (Merchant $merchant) => $this->transformMerchant($merchant))
                ->values(),
            'recent_transactions' => WalletTransaction::with(['merchant', 'voucher'])
                ->latest()
                ->take(15)
                ->get(),
            'pending_merchants' => $pendingMerchants->map(fn (Merchant $merchant) => $this->transformMerchant($merchant))->values(),
            'pending_venues' => $pendingVenues->map(fn (Venue $venue) => $this->transformVenue($venue))->values(),
            'integration_readiness' => $this->integrationReadinessService->dashboardPayload(),
            'offer_sync' => $this->offerSyncService->adminDashboardPayload(),
            'address_changes' => $this->addressApprovalService->adminDashboardPayload(),
            'provider_voucher_links' => [
                'summary' => $this->providerVoucherLinkService->summary(),
                'items' => $this->providerVoucherLinkService->listPayloads(8),
            ],
            'whatsapp_templates' => [
                'provider' => $whatsAppDashboard['provider'] ?? [],
                'summary' => $whatsAppDashboard['summary'] ?? [],
                'approved_template_keys' => $whatsAppDashboard['approved_template_keys'] ?? [],
                'preview' => $this->whatsAppTemplateService->approvedTemplatePreview(),
            ],
            'area_launch_alerts' => $this->areaLaunchAlertService->dashboardPayload((int) config('talktocas.area_launch_alerts.dashboard_recent_limit', 8)),
        ]);
    }

    public function merchants(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:all,pending,approved,rejected,active,inactive'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

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

            return $this->success([
                'summary' => $this->merchantSummary(),
                'items' => $paginated->getCollection()->map(fn (Merchant $merchant) => $this->transformMerchant($merchant))->values(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'last_page' => $paginated->lastPage(),
                ],
            ]);
        }

        return $this->success(
            $query->orderBy('business_name')->get()->map(
                fn (Merchant $merchant) => $this->transformMerchant($merchant)
            )->values()
        );
    }

    public function venues(Request $request)
    {
        $this->normalizeBooleanInput($request, 'active_only');

        $validated = $request->validate([
            'status' => ['nullable', 'in:all,pending,approved,rejected'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'category' => ['nullable', 'string', 'max:80'],
            'active_only' => ['nullable', 'boolean'],
        ]);

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

        $summary = [
            'pending' => Venue::where('approval_status', 'pending')->count(),
            'approved' => Venue::where('approval_status', 'approved')->count(),
            'rejected' => Venue::where('approval_status', 'rejected')->count(),
            'total' => Venue::count(),
        ];

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = (int) ($validated['per_page'] ?? 25);
            $paginated = $query->latest('submitted_for_approval_at')->paginate($perPage);

            return $this->success([
                'summary' => $summary,
                'items' => $paginated->getCollection()->map(fn (Venue $venue) => $this->transformVenue($venue))->values(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'last_page' => $paginated->lastPage(),
                ],
            ]);
        }

        return $this->success([
            'summary' => $summary,
            'items' => $query
                ->latest('submitted_for_approval_at')
                ->limit($limit)
                ->get()
                ->map(fn (Venue $venue) => $this->transformVenue($venue))
                ->values(),
        ]);
    }

    public function pendingMerchants()
    {
        return $this->success(
            Merchant::with(['user', 'wallet', 'venues.merchant.user'])
                ->whereHas('user', fn ($query) => $query->where('is_active', false))
                ->latest()
                ->get()
                ->map(fn (Merchant $merchant) => $this->transformMerchant($merchant))
                ->values()
        );
    }

    public function approveMerchant(Request $request, Merchant $merchant)
    {
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

        return $this->success([
            'merchant' => $this->transformMerchant($merchant->load(['user', 'wallet', 'venues.merchant.user'])),
            'area_launch_alert' => $areaLaunchAlert,
        ], 'Merchant approved successfully. Review pending venues separately to save manual venue codes.');
    }

    public function rejectMerchant(Request $request, Merchant $merchant)
    {
        $merchant->update(['status' => 'rejected']);
        $merchant->user?->update(['is_active' => false]);
        $merchant->venues()->where('approval_status', 'pending')->update(['is_active' => false]);

        return $this->success([
            'merchant' => $this->transformMerchant($merchant->load(['user', 'wallet', 'venues.merchant.user'])),
        ], 'Merchant rejected.');
    }

    public function updateMerchant(Request $request, Merchant $merchant)
    {
        $this->normalizeBooleanInput($request, 'user_is_active');

        $validated = $request->validate([
            'business_name' => ['sometimes', 'required', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'default_service_fee' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:pending,active,inactive,rejected'],
            'user_name' => ['nullable', 'string', 'max:255'],
            'user_email' => ['nullable', 'email', 'max:255'],
            'user_is_active' => ['nullable', 'boolean'],
            'wallet_low_balance_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);

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

        return $this->success([
            'merchant' => $this->transformMerchant($merchant->fresh(['user', 'wallet', 'venues.merchant.user'])),
        ], 'Merchant updated successfully.');
    }

    public function deleteMerchant(Merchant $merchant)
    {
        $user = $merchant->user;
        $merchantName = $merchant->business_name;
        $merchant->delete();

        if ($user) {
            $user->update(['is_active' => false]);
        }

        return $this->success([
            'deleted' => true,
            'merchant_name' => $merchantName,
        ], 'Merchant deleted successfully. Related merchant records were removed by database cascade rules.');
    }

    public function approveVenue(Request $request, Venue $venue)
    {
        $venue->load('merchant.user');

        if ($venue->merchant?->status !== 'active' || ! $venue->merchant?->user?->is_active) {
            return $this->error('Approve the merchant account before approving venues.', 422);
        }

        $validated = $request->validate([
            'venue_code' => [
                'required',
                'string',
                'size:6',
                'regex:/^[A-Za-z0-9]{6}$/',
                Rule::unique('venues', 'venue_code')->ignore($venue->id),
            ],
        ]);

        $venue->update([
            'approval_status' => 'approved',
            'venue_code' => strtoupper(trim($validated['venue_code'])),
            'is_active' => true,
            'approved_at' => now(),
            'approved_by_user_id' => $request->user()->id,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh(['merchant.user'])),
        ], 'Venue approved with manual 6 character alphanumeric code.');
    }

    public function rejectVenue(Request $request, Venue $venue)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $venue->update([
            'approval_status' => 'rejected',
            'is_active' => false,
            'rejected_at' => now(),
            'rejection_reason' => $validated['reason'] ?? null,
        ]);

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh(['merchant.user'])),
        ], 'Venue rejected successfully.');
    }

    public function updateVenue(Request $request, Venue $venue)
    {
        $this->normalizeBooleanInput($request, 'is_active');
        $this->normalizeBooleanInput($request, 'offer_enabled');
        $this->normalizeBooleanInput($request, 'urgency_enabled');

        $validated = $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'approval_status' => ['nullable', 'in:pending,approved,rejected'],
            'venue_code' => [
                'nullable',
                'string',
                'size:6',
                'regex:/^[A-Za-z0-9]{6}$/',
                Rule::unique('venues', 'venue_code')->ignore($venue->id),
            ],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
            'offer_enabled' => ['nullable', 'boolean'],
            'offer_value' => ['nullable', 'numeric', 'min:0'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'fulfilment_type' => ['nullable', 'string', 'max:80'],
            'urgency_enabled' => ['nullable', 'boolean'],
            'daily_voucher_cap' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'offer_type' => ['nullable', 'in:food,ride,dual_choice'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'promo_message' => ['nullable', 'string', 'max:2000'],
        ]);

        if (($validated['approval_status'] ?? null) === 'approved') {
            if (empty($validated['venue_code']) && empty($venue->venue_code)) {
                return $this->error('Approved venues need a manual 6 character alphanumeric venue code.', 422);
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

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh(['merchant.user', 'approvedBy'])),
        ], 'Venue updated successfully.');
    }

    public function deleteVenue(Venue $venue)
    {
        $venueName = $venue->name;
        $venue->delete();

        return $this->success([
            'deleted' => true,
            'venue_name' => $venueName,
        ], 'Venue deleted successfully. Related venue records were removed by database cascade rules.');
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
