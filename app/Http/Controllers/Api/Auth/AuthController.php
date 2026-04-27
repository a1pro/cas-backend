<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Mail\MerchantApprovalRequiredMail;
use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Venue;
use App\Services\Affiliate\AffiliateTrackingService;
use App\Services\Merchant\MerchantBusinessRuleService;
use App\Services\Tag\TagButtonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends BaseController
{
    public function __construct(
        private readonly MerchantBusinessRuleService $merchantBusinessRuleService,
    ) {
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['nullable', 'in:user,merchant'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'referral_code' => ['nullable', 'string', 'max:24', 'exists:affiliate_profiles,share_code'],
        ]);

        $role = $validated['role'] ?? 'user';

        if ($role === 'merchant') {
            return $this->registerMerchant(new Request([
                'owner_name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'business_name' => $validated['business_name'] ?? $validated['name'],
                'business_type' => $validated['business_type'] ?? 'club',
                'contact_phone' => $validated['contact_phone'] ?? null,
                'postcode' => $request->input('postcode'),
            ]));
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone' => $validated['contact_phone'] ?? null,
            'postcode' => isset($validated['postcode']) ? strtoupper(trim((string) $validated['postcode'])) : null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'is_active' => true,
            'email_verified_at' => now(),
            'role' => 'user',
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role' => 'user',
            'assigned_at' => now(),
        ]);

        if (! empty($validated['referral_code'])) {
            app(AffiliateTrackingService::class)->attachReferredUser($user, $validated['referral_code']);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $this->profilePayload($user),
        ], 'Registered successfully', 201);
    }

    public function merchantEligibility(Request $request)
    {
        $validated = $request->validate([
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'in:club,bar,restaurant,takeaway,cafe'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'venue_description' => ['nullable', 'string', 'max:1000'],
            'requested_plan' => ['nullable', 'in:free_trial,payg'],
        ]);

        return $this->success(
            $this->merchantBusinessRuleService->evaluateRegistration($validated),
            'Merchant plan eligibility checked successfully.'
        );
    }

    public function registerMerchant(Request $request)
    {
        $validated = $request->validate([
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'in:club,bar,restaurant,takeaway,cafe'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['required', 'string', 'max:20'],
            'low_balance_threshold' => ['nullable', 'numeric', 'min:1'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'venue_description' => ['nullable', 'string', 'max:1000'],
            'tag_code' => ['nullable', 'string', 'max:24', 'exists:venue_tags,share_code'],
            'requested_plan' => ['nullable', 'in:free_trial,payg'],
        ]);

        $eligibility = $this->merchantBusinessRuleService->evaluateRegistration($validated);

        $user = User::create([
            'name' => $validated['owner_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone' => $validated['contact_phone'],
            'is_active' => false,
            'email_verified_at' => now(),
            'role' => 'merchant',
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role' => 'merchant',
            'assigned_at' => now(),
        ]);

        $merchant = Merchant::create([
            'user_id' => $user->id,
            'business_name' => $validated['business_name'],
            'business_type' => $validated['business_type'],
            'contact_email' => $validated['email'],
            'contact_phone' => $validated['contact_phone'],
            'whatsapp_number' => $validated['whatsapp_number'] ?? $validated['contact_phone'],
            'default_service_fee' => 2.50,
            'status' => 'inactive',
            'onboarding_plan' => $eligibility['resolved_plan'],
            'free_trial_status' => $eligibility['free_trial_status'],
            'free_trial_ineligible_reason' => $eligibility['free_trial_ineligible_reason'],
            'free_trial_message' => $eligibility['free_trial_message'],
            'trial_blocked_keywords' => $eligibility['blocked_keywords'],
            'normalized_trial_address' => $eligibility['normalized_trial_address'],
        ]);

        MerchantWallet::create([
            'merchant_id' => $merchant->id,
            'balance' => 0,
            'currency' => 'GBP',
            'low_balance_threshold' => $validated['low_balance_threshold'] ?? 50,
            'auto_top_up_enabled' => false,
            'auto_top_up_amount' => $this->merchantBusinessRuleService->minimumTopUpAmount(),
        ]);

        Venue::create([
            'merchant_id' => $merchant->id,
            'name' => $validated['business_name'],
            'category' => $validated['business_type'],
            'city' => $validated['city'] ?? null,
            'postcode' => strtoupper($validated['postcode']),
            'address' => $validated['address'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'description' => $validated['venue_description'] ?? null,
            'is_active' => false,
            'approval_status' => 'pending',
            'submitted_for_approval_at' => now(),
            'offer_enabled' => false,
            'offer_value' => 5,
            'offer_days' => ['friday', 'saturday'],
            'offer_start_time' => '18:00:00',
            'offer_end_time' => '23:59:00',
            'minimum_order' => $this->isFoodBusiness($validated['business_type']) ? 25 : null,
            'fulfilment_type' => $this->isFoodBusiness($validated['business_type']) ? 'delivery' : 'venue',
            'offer_review_status' => 'draft',
            'offer_type' => $this->isFoodBusiness($validated['business_type']) ? 'food' : 'ride',
        ]);

        $tagAttribution = null;
        if (! empty($validated['tag_code'])) {
            $tagAttribution = app(TagButtonService::class)->markMerchantJoined($validated['tag_code'], $merchant);
        }

        $adminEmail = env('ADMIN_APPROVAL_EMAIL');
        if ($adminEmail) {
            try {
                Mail::to($adminEmail)->send(new MerchantApprovalRequiredMail($merchant));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->success([
            'merchant_id' => $merchant->id,
            'status' => 'pending_approval',
            'onboarding_plan' => $eligibility['resolved_plan'],
            'free_trial' => $eligibility,
            'message' => $eligibility['free_trial_message'],
            'tag_attribution' => $tagAttribution ? [
                'share_code' => $tagAttribution->share_code,
                'status' => $tagAttribution->status,
                'reward_credit' => '5.00',
            ] : null,
        ], 'Merchant registration submitted successfully', 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $loginValue = trim($validated['login']);
        $user = User::query()
            ->where('email', $loginValue)
            ->orWhere('phone', $loginValue)
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return $this->error('Invalid credentials', 401);
        }

        if (! $user->is_active) {
            return $this->error('Your account is waiting for admin approval.', 403, [
                'approval_required' => true,
                'role' => $user->primaryRole(),
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $this->profilePayload($user),
        ], 'Logged in successfully');
    }

    public function me(Request $request)
    {
        return $this->success($this->profilePayload($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->success([], 'Logged out successfully');
    }

    private function isFoodBusiness(string $businessType): bool
    {
        return in_array($businessType, ['restaurant', 'takeaway', 'cafe'], true);
    }

    private function profilePayload(User $user): array
    {
        $roles = $user->roles()->pluck('role')->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'postcode' => $user->postcode,
            'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
            'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
            'roles' => $roles,
            'primary_role' => $user->role ?: ($roles[0] ?? null),
            'merchant_id' => optional($user->merchant)->id,
            'affiliate_code' => optional($user->affiliateProfile)->share_code,
            'referred_by_user_id' => $user->referred_by_user_id ? (int) $user->referred_by_user_id : null,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
