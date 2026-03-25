<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Api\BaseController;
use App\Models\Venue;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserDashboardController extends BaseController
{
    public function dashboard(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'stats' => [
                'total_vouchers' => $user->vouchers()->count(),
                'redeemed_vouchers' => $user->vouchers()->where('status', 'redeemed')->count(),
                'issued_vouchers' => $user->vouchers()->where('status', 'issued')->count(),
            ],
            'recent_vouchers' => Voucher::with(['venue', 'merchant'])
                ->where('user_id', $user->id)
                ->latest()
                ->take(5)
                ->get(),
        ]);
    }

    public function venues(Request $request)
    {
        $city = $request->query('city');
        $query = Venue::with('merchant.wallet')->where('is_active', true);

        if ($city) {
            $query->where('city', $city);
        }

        return $this->success($query->orderBy('name')->get());
    }

    public function createVoucher(Request $request)
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'voucher_value' => ['nullable', 'numeric', 'min:0'],
            'promo_message' => ['nullable', 'string', 'max:80'],
        ]);

        $venue = Venue::with('merchant')->findOrFail($validated['venue_id']);
        $voucherValue = $validated['voucher_value'] ?? 5.00;
        $serviceFee = $venue->merchant->default_service_fee ?? 2.50;

        $voucher = Voucher::create([
            'user_id' => $request->user()->id,
            'merchant_id' => $venue->merchant_id,
            'venue_id' => $venue->id,
            'code' => 'TTC-' . strtoupper(Str::random(8)),
            'destination_postcode' => $venue->postcode,
            'promo_message' => $validated['promo_message'] ?? 'Enjoy your ride and check in at this venue tonight.',
            'voucher_value' => $voucherValue,
            'service_fee' => $serviceFee,
            'total_charge' => $voucherValue + $serviceFee,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        return $this->success($voucher->load(['venue', 'merchant']), 'Voucher created', 201);
    }
}
