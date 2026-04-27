<?php

namespace App\Http\Controllers\Api\WhatsApp;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppProviderWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProviderWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppProviderWebhookService $whatsAppProviderWebhookService)
    {
    }

    public function verify(Request $request)
    {
        $result = $this->whatsAppProviderWebhookService->verifyChallenge($request->query());

        if (! $result['valid']) {
            Log::warning('WhatsApp webhook verify challenge failed', [
                'query' => $request->query(),
            ]);

            return response()->json([
                'verified' => false,
                'message' => $result['reason'],
            ], 403);
        }

        return response((string) $result['challenge'], 200)
            ->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request)
    {
        try {
            $result = $this->whatsAppProviderWebhookService->handle($request->all(), [
                'raw_payload' => $request->getContent(),
                'signature' => $request->header('X-Hub-Signature-256'),
                'user-agent' => $request->userAgent(),
            ]);

            return response()->json([
                'received' => true,
                'signature_valid' => $result['signature_valid'] ?? null,
            ], 200);
        } catch (RuntimeException $throwable) {
            Log::warning('WhatsApp webhook signature rejected', [
                'message' => $throwable->getMessage(),
            ]);

            return response()->json([
                'received' => false,
                'message' => $throwable->getMessage(),
            ], 403);
        } catch (\Throwable $throwable) {
            Log::error('WhatsApp webhook processing failed', [
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ]);
        }

        return response()->json(['received' => true], 200);
    }
}
