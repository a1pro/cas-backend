<?php

namespace App\Http\Controllers\Api\WhatsApp;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppProviderWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProviderWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppProviderWebhookService $whatsAppProviderWebhookService)
    {
    }

    public function verify(Request $request)
    {
        try {
            $result = $this->whatsAppProviderWebhookService->verifyChallenge($request->query());

            if (! $result['valid']) {
                Log::warning('WhatsApp webhook verify challenge failed', [
                    'query' => $request->query(),
                ]);

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => $result['reason'],
                    'data' => null,
                ], 403);
            }

            return response((string) $result['challenge'], 200)
                ->header('Content-Type', 'text/plain');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function handle(Request $request)
    {
        try {
            DB::beginTransaction();

            $result = $this->whatsAppProviderWebhookService->handle($request->all(), [
                'raw_payload' => $request->getContent(),
                'signature' => $request->header('X-Hub-Signature-256'),
                'user-agent' => $request->userAgent(),
            ]);

            $data = [
                'received' => true,
                'signature_valid' => $result['signature_valid'] ?? null,
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        } catch (RuntimeException $e) {
            DB::rollBack();

            Log::warning('WhatsApp webhook signature rejected', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'status_code' => 403,
                'message' => $e->getMessage(),
                'data' => null,
            ], 403);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('WhatsApp webhook processing failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }
}
