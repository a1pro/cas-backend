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
use Illuminate\Support\Facades\DB;
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
        try {
            $user = $request->user();

            $data = [
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

    public function updateProviderProfile(Request $request)
    {
        try {
            DB::beginTransaction();

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

            $data = [
                'provider_profile' => $this->couponEligibilityService->profilePayload($user->fresh()),
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
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

    public function updatePayoutProfile(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'payout_email' => ['nullable', 'email', 'max:255'],
                'country_code' => ['nullable', 'string', 'size:2'],
                'currency' => ['nullable', 'string', 'size:3'],
            ]);

            $this->stripeFinanceService->updatePayoutProfile($request->user(), $validated);

            $data = [
                'payout_profile' => $this->stripeFinanceService->payoutProfilePayload(
                    $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
                ),
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
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

    public function startPayoutOnboarding(Request $request)
    {
        try {
            DB::beginTransaction();

            $profile = $this->stripeFinanceService->startAffiliateOnboarding($request->user());

            $data = [
                'payout_profile' => $this->stripeFinanceService->payoutProfilePayload(
                    $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
                ),
                'profile_status' => $profile->onboarding_status,
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
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

    public function simulatePayoutApproval(Request $request)
    {
        try {
            DB::beginTransaction();

            $profile = $this->stripeFinanceService->simulateAffiliateApproval($request->user());

            $data = [
                'payout_profile' => $this->stripeFinanceService->payoutProfilePayload(
                    $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
                ),
                'profile_status' => $profile->onboarding_status,
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
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

    public function createPayoutRun(Request $request)
    {
        try {
            DB::beginTransaction();

            try {
                $run = $this->stripeFinanceService->createAffiliatePayoutRun($request->user());
            } catch (RuntimeException $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            $data = [
                'payout_run' => $run,
                'payout_profile' => $this->stripeFinanceService->payoutProfilePayload(
                    $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
                ),
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
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

    public function simulatePayoutRun(Request $request, string $payoutCode)
    {
        try {
            DB::beginTransaction();

            try {
                $result = $this->stripeFinanceService->simulateAffiliatePayout($request->user(), $payoutCode);
            } catch (RuntimeException $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            $data = [
                'payout_run' => $result['run'],
                'already_processed' => $result['already_processed'] ?? false,
                'payout_profile' => $this->stripeFinanceService->payoutProfilePayload(
                    $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
                ),
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
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

    public function sendAffiliateTestAlert(Request $request)
    {
        try {
            DB::beginTransaction();

            $data = $this->affiliateCommissionService->sendTestAlert($request->user());

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
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

    public function venues(Request $request)
    {
        try {
            $city = $request->query('city');
            $query = Venue::with('merchant.wallet')
                ->where('is_active', true)
                ->where('offer_enabled', true);

            if ($city) {
                $query->where('city', $city);
            }

            $data = $query->orderByDesc('offer_value')->orderBy('name')->get();

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

    public function createVoucher(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'venue_id' => ['required', 'exists:venues,id'],
                'promo_message' => ['nullable', 'string', 'max:80'],
                'basket_total' => ['nullable', 'numeric', 'min:0'],
            ]);

            $venue = Venue::with('merchant')->findOrFail($validated['venue_id']);

            if (! $venue->offer_enabled) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'This venue does not currently have vouchers enabled.',
                ], 422);
            }

            if (in_array($venue->category, ['restaurant', 'takeaway', 'cafe'], true) && $venue->minimum_order !== null) {
                $basketTotal = (float) ($validated['basket_total'] ?? 0);

                if ($basketTotal < (float) $venue->minimum_order) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'status_code' => 422,
                        'message' => 'This restaurant offer requires a higher basket total.',
                        'errors' => [
                            'minimum_order' => $venue->minimum_order,
                        ],
                    ], 422);
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
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => $exception->getMessage() ?: 'Voucher request blocked by fraud checks.',
                    'errors' => $exception->errors(),
                ], 422);
            }

            $voucher->load(['venue', 'merchant', 'user']);

            $data = $voucher;

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
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
}
