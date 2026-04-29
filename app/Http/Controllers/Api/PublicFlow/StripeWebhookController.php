<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Bnpl\BnplCheckoutService;
use App\Services\Payments\StripeFinanceService;
use Illuminate\Http\Request;
use RuntimeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class StripeWebhookController extends BaseController
{
    public function __construct(
        private readonly StripeFinanceService $stripeFinanceService,
        private readonly BnplCheckoutService $bnplCheckoutService,
    ) {
    }

    public function handle(Request $request)
    {
        try {
            DB::beginTransaction();

            try {
                $payload = $request->all();
                $context = [
                    'stripe-signature' => $request->header('Stripe-Signature'),
                    'user-agent' => $request->userAgent(),
                    'raw_payload' => $request->getContent(),
                ];

                $stripeResult = $this->stripeFinanceService->handleWebhookPayload($payload, $context);
                $bnplResult = $this->bnplCheckoutService->handleStripeWebhookPayload($payload);
                $handled = (bool) ($stripeResult['handled'] ?? false) || (bool) ($bnplResult['handled'] ?? false);

                $data = [
                        'handled' => $handled,
                        'stripe' => $stripeResult,
                        'bnpl' => $bnplResult,
                    ];

                DB::commit();

                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => $handled
                        ? 'Stripe webhook processed successfully.'
                        : 'Stripe webhook received but no TALK to CAS handler matched it.',
                    'data' => $data,
                ], 200);
            } catch (RuntimeException $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => $exception->getMessage(),
                ], 403);
            }
        
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
