<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\WhatsApp\ThreeSixtyDialogService;
use App\Services\WhatsApp\WhatsAppTemplateService;

class WhatsAppConnectController extends BaseController
{
    public function __construct(
        private readonly ThreeSixtyDialogService $threeSixtyDialogService,
        private readonly WhatsAppTemplateService $whatsAppTemplateService,
    ) {
    }

    public function show()
    {
        $prompts = [
            'Going-Out Tonight',
            'Ordering-Food',
            'Share location',
            'Send postcode',
            'Pick a venue',
            'Get voucher',
        ];

        return $this->success([
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
        ]);
    }
}
