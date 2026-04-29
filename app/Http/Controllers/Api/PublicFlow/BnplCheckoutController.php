<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Models\BnplVoucherOrder;
use App\Services\Bnpl\BnplCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class BnplCheckoutController extends BaseController
{
    public function __construct(private readonly BnplCheckoutService $bnplCheckoutService)
    {
    }

    public function options()
    {
        try {
            $data = $this->bnplCheckoutService->options();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'status_code' => 404,
                'message' => 'Resource not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            if (! config('talktocas.bnpl.enabled', false)) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Voucher upgrades are not enabled right now.',
                ], 422);
            }

            $validated = $request->validate([
                'plan_key' => ['required', 'string', 'max:50'],
                'customer_name' => ['required', 'string', 'max:255'],
                'customer_email' => ['required', 'email', 'max:255'],
                'customer_phone' => ['required', 'string', 'max:50'],
                'source_context' => ['nullable', 'string', 'max:100'],
            ]);

            try {
                $order = $this->bnplCheckoutService->createOrder(auth('sanctum')->user(), $validated);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'plan_key' => [$exception->getMessage()],
                ]);
            }

            $data = $this->bnplCheckoutService->payload($order);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Voucher upgrade checkout created.',
                'data' => $data,
            ], 201);
        
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 404,
                'message' => 'Resource not found.',
            ], 404);
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

    public function show(string $checkoutCode)
    {
        try {
            $order = BnplVoucherOrder::query()->where('checkout_code', strtoupper(trim($checkoutCode)))->firstOrFail();

            $data = $this->bnplCheckoutService->payload($order);

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'status_code' => 404,
                'message' => 'Resource not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function simulatePayment(string $checkoutCode)
    {
        try {
            DB::beginTransaction();

            if (! config('talktocas.bnpl.allow_simulated_payment', false)) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => 'Simulated payment confirmation is disabled.',
                ], 403);
            }

            $order = BnplVoucherOrder::query()->where('checkout_code', strtoupper(trim($checkoutCode)))->firstOrFail();
            $order = $this->bnplCheckoutService->markPaymentConfirmed($order, [
                'simulated_payment' => true,
                'settlement_mode' => 'simulated_localhost',
                'payment_reference' => 'BNPL-SIM-' . strtoupper(substr($order->checkout_code, -6)),
                'simulated_at' => now()->toIso8601String(),
            ]);

            $data = $this->bnplCheckoutService->payload($order);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Simulated payment applied. Voucher upgrade is now marked as issued.',
                'data' => $data,
            ], 200);
        
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 404,
                'message' => 'Resource not found.',
            ], 404);
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
