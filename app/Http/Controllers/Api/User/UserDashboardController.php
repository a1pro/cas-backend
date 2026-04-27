<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Api\BaseController;
use App\Models\Venue;
use App\Models\Voucher;
use App\Services\Affiliate\AffiliateCommissionService;
use App\Services\Affiliate\AffiliateTrackingService;
use App\Services\Bnpl\BnplCheckoutService;
use App\Services\Coupon\CouponEligibilityService;
use App\Services\Fraud\FraudPreventionService;
use App\Services\Payments\StripeFinanceService;
use App\Services\Voucher\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UserDashboardController extends BaseController
{
    public function __construct(
        private readonly AffiliateTrackingService $affiliateTrackingService,
        private readonly AffiliateCommissionService $affiliateCommissionService,
        private readonly FraudPreventionService $fraudPreventionService,
        private readonly VoucherService $voucherService,
        private readonly BnplCheckoutService $bnplCheckoutService,
        private readonly CouponEligibilityService $couponEligibilityService,
        private readonly StripeFinanceService $stripeFinanceService,
    ) {
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => optional($user->email_verified_at)?->toIso8601String(),
                'phone' => $user->phone,
                'phone_verified_at' => optional($user->phone_verified_at)?->toIso8601String(),
                'postcode' => $user->postcode,
                'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
                'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
            ],
            'provider_profile' => $this->couponEligibilityService->profilePayload($user),
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
            'recent_bnpl_orders' => $this->bnplCheckoutService->payloadListForUser($user, 5),
            'affiliate' => $this->affiliateTrackingService->dashboardForUser($user),
            'affiliate_reports' => $this->affiliateCommissionService->dashboardForUser($user, 8),
            'payout_profile' => $this->stripeFinanceService->payoutProfilePayload($user),
            'fraud' => $this->fraudPreventionService->statusForUser($user),
        ]);
    }

    public function updateProviderProfile(Request $request)
    {
        $validated = $request->validate([
            'is_uber_existing_customer' => ['nullable', 'boolean'],
            'is_ubereats_existing_customer' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $user->update([
            'is_uber_existing_customer' => $validated['is_uber_existing_customer'] ?? null,
            'is_ubereats_existing_customer' => $validated['is_ubereats_existing_customer'] ?? null,
            'provider_profile_updated_at' => now(),
        ]);

        return $this->success([
            'provider_profile' => $this->couponEligibilityService->profilePayload($user->fresh()),
        ], 'Provider profile updated successfully.');
    }

    public function updatePayoutProfile(Request $request)
    {
        $validated = $request->validate([
            'payout_email' => ['nullable', 'email', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $this->stripeFinanceService->updatePayoutProfile($request->user(), $validated);

        return $this->success([
            'payout_profile' => $this->stripeFinanceService->payoutProfilePayload($request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])),
        ], 'Affiliate payout profile updated successfully.');
    }

    public function startPayoutOnboarding(Request $request)
    {
        $profile = $this->stripeFinanceService->startAffiliateOnboarding($request->user());

        return $this->success([
            'payout_profile' => $this->stripeFinanceService->payoutProfilePayload($request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])),
            'profile_status' => $profile->onboarding_status,
        ], 'Stripe Connect onboarding started successfully.');
    }

    public function simulatePayoutApproval(Request $request)
    {
        $profile = $this->stripeFinanceService->simulateAffiliateApproval($request->user());

        return $this->success([
            'payout_profile' => $this->stripeFinanceService->payoutProfilePayload($request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])),
            'profile_status' => $profile->onboarding_status,
        ], 'Stripe Connect approval simulated successfully.');
    }

    public function createPayoutRun(Request $request)
    {
        try {
            $run = $this->stripeFinanceService->createAffiliatePayoutRun($request->user());
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'payout_run' => $run,
            'payout_profile' => $this->stripeFinanceService->payoutProfilePayload($request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])),
        ], 'Affiliate payout run created successfully.');
    }

    public function simulatePayoutRun(Request $request, string $payoutCode)
    {
        try {
            $result = $this->stripeFinanceService->simulateAffiliatePayout($request->user(), $payoutCode);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'payout_run' => $result['run'],
            'already_processed' => $result['already_processed'] ?? false,
            'payout_profile' => $this->stripeFinanceService->payoutProfilePayload($request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])),
        ], 'Affiliate payout marked as paid successfully.');
    }


    public function sendAffiliateTestAlert(Request $request)
    {
        $result = $this->affiliateCommissionService->sendTestAlert($request->user());

        return $this->success($result, $result['sent'] ? 'Affiliate alert test sent successfully.' : 'No alert channel is configured for this account yet.');
    }

    public function venues(Request $request)
    {
        $city = $request->query('city');
        $query = Venue::with('merchant.wallet')
            ->where('is_active', true)
            ->where('offer_enabled', true);

        if ($city) {
            $query->where('city', $city);
        }

        return $this->success($query->orderByDesc('offer_value')->orderBy('name')->get());
    }

    public function createVoucher(Request $request)
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'promo_message' => ['nullable', 'string', 'max:80'],
            'basket_total' => ['nullable', 'numeric', 'min:0'],
        ]);

        $venue = Venue::with('merchant')->findOrFail($validated['venue_id']);

        if (! $venue->offer_enabled) {
            return $this->error('This venue does not currently have vouchers enabled.', 422);
        }

        if (in_array($venue->category, ['restaurant', 'takeaway', 'cafe'], true) && $venue->minimum_order !== null) {
            $basketTotal = (float) ($validated['basket_total'] ?? 0);
            if ($basketTotal < (float) $venue->minimum_order) {
                return $this->error('This restaurant offer requires a higher basket total.', 422, [
                    'minimum_order' => $venue->minimum_order,
                ]);
            }
        }

        $journeyType = in_array($venue->category, ['restaurant', 'takeaway', 'cafe'], true) ? 'food' : 'ride';

        try {
            $voucher = $this->voucherService->issue(
                $request->user(),
                $venue,
                $journeyType,
                $request->header('X-Device-Fingerprint')
            );
        } catch (ValidationException $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Voucher request blocked by fraud checks.',
                422,
                $exception->errors()
            );
        }

        $voucher->load(['venue', 'merchant', 'user']);

        return $this->success($voucher, 'Voucher created', 201);
    }
}
