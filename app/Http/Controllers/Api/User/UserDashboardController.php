<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\User\CreateUserVoucherRequest;
use App\Http\Requests\User\UpdatePayoutProfileRequest;
use App\Http\Requests\User\UpdateProviderProfileRequest;
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

            $totalVouchers = $user->vouchers()->count();
            $redeemedVouchers = $user->vouchers()->where('status', 'redeemed')->count();
            $issuedVouchers = $user->vouchers()->where('status', 'issued')->count();

            $recentVouchers = Voucher::with(['venue', 'merchant'])
                ->where('user_id', $user->id)
                ->latest()
                ->take(5)
                ->get();

            $providerProfile = $this->couponEligibilityService->profilePayload($user);
            $recentBnplOrders = $this->bnplCheckoutService->payloadListForUser($user, 5);
            $affiliate = $this->affiliateTrackingService->dashboardForUser($user);
            $affiliateReports = $this->affiliateCommissionService->dashboardForUser($user, 8);
            $payoutProfile = $this->stripeFinanceService->payoutProfilePayload($user);
            $fraudStatus = $this->fraudPreventionService->statusForUser($user);

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
                'provider_profile' => $providerProfile,
                'stats' => [
                    'total_vouchers' => $totalVouchers,
                    'redeemed_vouchers' => $redeemedVouchers,
                    'issued_vouchers' => $issuedVouchers,
                ],
                'recent_vouchers' => $recentVouchers,
                'recent_bnpl_orders' => $recentBnplOrders,
                'affiliate' => $affiliate,
                'affiliate_reports' => $affiliateReports,
                'payout_profile' => $payoutProfile,
                'fraud' => $fraudStatus,
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

    public function updateProviderProfile(UpdateProviderProfileRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $user = $request->user();
            $user->update([
                'is_uber_existing_customer' => $validated['is_uber_existing_customer'] ?? null,
                'is_ubereats_existing_customer' => $validated['is_ubereats_existing_customer'] ?? null,
                'provider_profile_updated_at' => now(),
            ]);

            $providerProfile = $this->couponEligibilityService->profilePayload($user->fresh());

            $data = [
                'provider_profile' => $providerProfile,
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

    public function updatePayoutProfile(UpdatePayoutProfileRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $this->stripeFinanceService->updatePayoutProfile($request->user(), $validated);

            $payoutProfile = $this->stripeFinanceService->payoutProfilePayload(
                $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
            );

            $data = [
                'payout_profile' => $payoutProfile,
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
            $payoutProfile = $this->stripeFinanceService->payoutProfilePayload(
                $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
            );

            $data = [
                'payout_profile' => $payoutProfile,
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
            $payoutProfile = $this->stripeFinanceService->payoutProfilePayload(
                $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
            );

            $data = [
                'payout_profile' => $payoutProfile,
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

            $payoutProfile = $this->stripeFinanceService->payoutProfilePayload(
                $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
            );

            $data = [
                'payout_run' => $run,
                'payout_profile' => $payoutProfile,
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

            $payoutProfile = $this->stripeFinanceService->payoutProfilePayload(
                $request->user()->fresh(['affiliateProfile', 'affiliatePayoutProfile'])
            );

            $data = [
                'payout_run' => $result['run'],
                'already_processed' => $result['already_processed'] ?? false,
                'payout_profile' => $payoutProfile,
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

    public function createVoucher(CreateUserVoucherRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

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
