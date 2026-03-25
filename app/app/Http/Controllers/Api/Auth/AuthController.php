<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Models\MerchantWallet;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'phone' => $validated['contact_phone'] ?? null,
            'is_active' => true,
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role' => $validated['role'],
            'assigned_at' => now(),
        ]);

        if ($validated['role'] === 'merchant') {
            $merchant = Merchant::create([
                'user_id' => $user->id,
                'business_name' => $validated['business_name'] ?? $validated['name'],
                'business_type' => $validated['business_type'] ?? 'club',
                'contact_email' => $validated['email'],
                'contact_phone' => $validated['contact_phone'] ?? null,
                'whatsapp_number' => $validated['contact_phone'] ?? null,
                'default_service_fee' => 2.50,
                'status' => 'active',
            ]);

            MerchantWallet::create([
                'merchant_id' => $merchant->id,
                'balance' => 200,
                'currency' => 'GBP',
                'low_balance_threshold' => 50,
                'auto_top_up_enabled' => false,
                'auto_top_up_amount' => 100,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $this->profilePayload($user),
        ], 'Registered successfully', 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->error('Invalid credentials', 401);
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
            'primary_role' => $roles[0] ?? null,
            'merchant_id' => optional($user->merchant)->id,
        ];
    }
}
