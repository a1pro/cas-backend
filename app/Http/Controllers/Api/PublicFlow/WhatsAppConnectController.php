<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\WhatsApp\ThreeSixtyDialogService;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class WhatsAppConnectController extends BaseController
{
    public function __construct(
        private readonly ThreeSixtyDialogService $threeSixtyDialogService,
        private readonly WhatsAppTemplateService $whatsAppTemplateService,
    ) {
    }

    public function show()
    {
        try {
            $prompts = [
                'Going-Out Tonight',
                'Ordering-Food',
                'Share location',
                'Send postcode',
                'Pick a venue',
                'Get voucher',
            ];

            $data = [
                    'enabled' => $this->threeSixtyDialogService->enabled(),
                    'provider' => $this->threeSixtyDialogService->providerName(),
                    'display_phone_number' => $this->threeSixtyDialogService->displayPhoneNumber(),
                    'connect_url' => $this->threeSixtyDialogService->startLink(),
                    'provider_status' => $this->whatsAppTemplateService->providerStatus(),
                    'approved_templates_preview' => $this->whatsAppTemplateService->approvedTemplatePreview(),
                    'prompts' => $prompts,
                    'flow' => [
                        'Open site',
                        'Click WhatsApp',
                        'Tap Send in WhatsApp to begin or restart',
                        'Choose Going-Out Tonight or Ordering-Food',
                        'Share location or send postcode',
                        'See nearby venues',
                        'Select venue',
                        'Enter email or SKIP',
                        'Reply YES for consent',
                        'Receive voucher',
                    ],
                ];

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
}
