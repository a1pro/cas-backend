<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Voucher\VoucherProviderVerificationService;
use Illuminate\Http\Request;
use RuntimeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class ProviderSettlementWebhookController extends BaseController
{
    public function __construct(private readonly VoucherProviderVerificationService $voucherProviderVerificationService)
    {
    }

    public function handle(Request $request)
    {
        try {
            DB::beginTransaction();

            try {
                $headers = [
                    'x-talktocas-provider-signature' => $request->header('X-TALKTOCAS-PROVIDER-SIGNATURE'),
                    'x-provider-signature' => $request->header('X-Provider-Signature'),
                    'user-agent' => $request->userAgent(),
                    'raw_payload' => $request->getContent(),
                ];

                $result = $this->voucherProviderVerificationService->handleWebhookPayload($request->all(), $headers);

                $data = $result;

                DB::commit();

                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => 'Provider webhook processed successfully.',
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
