<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Mail\MerchantApprovalRequiredMail;
use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends BaseController
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['required', 'in:user,merchant'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validated['role'] === 'merchant') {
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
            'is_active' => true,
            'email_verified_at' => now(),
            'role' => 'user',
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role' => 'user',
            'assigned_at' => now(),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $this->profilePayload($user),
        ], 'Registered successfully', 201);
    }

    public function registerMerchant(Request $request)
    {
        $validated = $request->validate([
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'in:club,bar,restaurant'],
            'contact_phone' => ['required', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['required', 'string', 'max:20'],
            'low_balance_threshold' => ['nullable', 'numeric', 'min:1'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'venue_description' => ['nullable', 'string', 'max:1000'],
        ]);

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
        ]);

        MerchantWallet::create([
            'merchant_id' => $merchant->id,
            'balance' => 0,
            'currency' => 'GBP',
            'low_balance_threshold' => $validated['low_balance_threshold'] ?? 50,
            'auto_top_up_enabled' => false,
            'auto_top_up_amount' => 100,
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
            'offer_enabled' => false,
            'offer_value' => 5,
            'offer_days' => ['friday', 'saturday'],
            'offer_start_time' => '18:00:00',
            'offer_end_time' => '23:59:00',
            'minimum_order' => $validated['business_type'] === 'restaurant' ? 25 : null,
            'fulfilment_type' => $validated['business_type'] === 'restaurant' ? 'delivery' : 'venue',
            'offer_review_status' => 'draft',
            'offer_type' => $validated['business_type'] === 'restaurant' ? 'food' : 'ride',
        ]);

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
            'message' => 'Merchant registration submitted. Waiting for admin approval.',
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

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->error('Invalid credentials', 401);
        }

        if (!$user->is_active) {
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

    private function profilePayload(User $user): array
    {
        $roles = $user->roles()->pluck('role')->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $roles,
            'primary_role' => $user->role ?: ($roles[0] ?? null),
            'merchant_id' => optional($user->merchant)->id,
            'is_active' => (bool) $user->is_active,
        ];
    }
}
