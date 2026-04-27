<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Bnpl\BnplCheckoutService;
use App\Services\Payments\StripeFinanceService;
use Illuminate\Http\Request;
use RuntimeException;

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
            $payload = $request->all();
            $context = [
                'stripe-signature' => $request->header('Stripe-Signature'),
                'user-agent' => $request->userAgent(),
                'raw_payload' => $request->getContent(),
            ];

            $stripeResult = $this->stripeFinanceService->handleWebhookPayload($payload, $context);
            $bnplResult = $this->bnplCheckoutService->handleStripeWebhookPayload($payload);
            $handled = (bool) ($stripeResult['handled'] ?? false) || (bool) ($bnplResult['handled'] ?? false);

            return $this->success([
                'handled' => $handled,
                'stripe' => $stripeResult,
                'bnpl' => $bnplResult,
            ], $handled
                ? 'Stripe webhook processed successfully.'
                : 'Stripe webhook received but no TALK to CAS handler matched it.');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 403);
        }
    }
}
