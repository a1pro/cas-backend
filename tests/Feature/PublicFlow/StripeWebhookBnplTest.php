<?php

namespace Tests\Feature\PublicFlow;

use App\Models\BnplVoucherOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookBnplTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_webhook_marks_bnpl_order_paid_and_issued(): void
    {
        $order = BnplVoucherOrder::create([
            'checkout_code' => 'BNPL-TESTPAY01',
            'plan_key' => 'standard',
            'plan_name' => 'Standard',
            'amount_gbp' => 30.00,
            'payment_provider' => 'stripe_bnpl',
            'payment_status' => 'pending',
            'voucher_status' => 'pending_payment',
            'customer_name' => 'Casey User',
            'customer_email' => 'casey@example.com',
            'customer_phone' => '+441234567890',
            'metadata' => [
                'payment_reference' => 'pi_bnpl_pending_test',
            ],
        ]);

        $this->postJson('/api/v1/stripe/webhook', [
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_live_test_123',
                    'metadata' => [
                        'checkout_code' => $order->checkout_code,
                    ],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.handled', true)
            ->assertJsonPath('data.bnpl.status', 'paid');

        $order->refresh();

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('issued', $order->voucher_status);
        $this->assertNotEmpty($order->voucher_code);
    }

    public function test_failed_webhook_marks_bnpl_order_failed_with_reason(): void
    {
        $order = BnplVoucherOrder::create([
            'checkout_code' => 'BNPL-TESTFAIL1',
            'plan_key' => 'standard',
            'plan_name' => 'Standard',
            'amount_gbp' => 30.00,
            'payment_provider' => 'stripe_bnpl',
            'payment_status' => 'pending',
            'voucher_status' => 'pending_payment',
            'customer_name' => 'Casey User',
            'customer_email' => 'casey@example.com',
            'customer_phone' => '+441234567890',
            'metadata' => [
                'payment_reference' => 'pi_bnpl_pending_fail',
            ],
        ]);

        $this->postJson('/api/v1/stripe/webhook', [
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_live_test_failed',
                    'metadata' => [
                        'checkout_code' => $order->checkout_code,
                    ],
                    'last_payment_error' => [
                        'message' => 'Card was declined.',
                    ],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.handled', true)
            ->assertJsonPath('data.bnpl.status', 'failed');

        $order->refresh();

        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('payment_failed', $order->voucher_status);
        $this->assertSame('Card was declined.', $order->metadata['failure_reason'] ?? null);
    }
}
