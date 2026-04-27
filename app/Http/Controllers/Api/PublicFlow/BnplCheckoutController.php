<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Models\BnplVoucherOrder;
use App\Services\Bnpl\BnplCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BnplCheckoutController extends BaseController
{
    public function __construct(private readonly BnplCheckoutService $bnplCheckoutService)
    {
    }

    public function options()
    {
        return $this->success($this->bnplCheckoutService->options());
    }

    public function store(Request $request)
    {
        if (! config('talktocas.bnpl.enabled', false)) {
            return $this->error('Voucher upgrades are not enabled right now.', 422);
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

        return $this->success(
            $this->bnplCheckoutService->payload($order),
            'Voucher upgrade checkout created.',
            201
        );
    }

    public function show(string $checkoutCode)
    {
        $order = BnplVoucherOrder::query()->where('checkout_code', strtoupper(trim($checkoutCode)))->firstOrFail();

        return $this->success($this->bnplCheckoutService->payload($order));
    }

    public function simulatePayment(string $checkoutCode)
    {
        if (! config('talktocas.bnpl.allow_simulated_payment', false)) {
            return $this->error('Simulated payment confirmation is disabled.', 403);
        }

        $order = BnplVoucherOrder::query()->where('checkout_code', strtoupper(trim($checkoutCode)))->firstOrFail();
        $order = $this->bnplCheckoutService->markPaymentConfirmed($order, [
            'simulated_payment' => true,
            'settlement_mode' => 'simulated_localhost',
            'payment_reference' => 'BNPL-SIM-' . strtoupper(substr($order->checkout_code, -6)),
            'simulated_at' => now()->toIso8601String(),
        ]);

        return $this->success(
            $this->bnplCheckoutService->payload($order),
            'Simulated payment applied. Voucher upgrade is now marked as issued.'
        );
    }
}
