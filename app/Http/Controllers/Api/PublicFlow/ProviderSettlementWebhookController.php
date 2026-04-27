<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Voucher\VoucherProviderVerificationService;
use Illuminate\Http\Request;
use RuntimeException;

class ProviderSettlementWebhookController extends BaseController
{
    public function __construct(private readonly VoucherProviderVerificationService $voucherProviderVerificationService)
    {
    }

    public function handle(Request $request)
    {
        try {
            $headers = [
                'x-talktocas-provider-signature' => $request->header('X-TALKTOCAS-PROVIDER-SIGNATURE'),
                'x-provider-signature' => $request->header('X-Provider-Signature'),
                'user-agent' => $request->userAgent(),
                'raw_payload' => $request->getContent(),
            ];

            $result = $this->voucherProviderVerificationService->handleWebhookPayload($request->all(), $headers);

            return $this->success($result, 'Provider webhook processed successfully.');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 403);
        }
    }
}
