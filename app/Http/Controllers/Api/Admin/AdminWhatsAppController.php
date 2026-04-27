<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\CasMessageTemplate;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Http\Request;

class AdminWhatsAppController extends BaseController
{
    public function __construct(private readonly WhatsAppTemplateService $whatsAppTemplateService)
    {
    }

    public function index()
    {
        return $this->success($this->whatsAppTemplateService->dashboardPayload());
    }

    public function starterPack(Request $request)
    {
        $validated = $request->validate([
            'overwrite_existing' => ['nullable', 'boolean'],
        ]);

        $result = $this->whatsAppTemplateService->installStarterPack((bool) ($validated['overwrite_existing'] ?? false));

        return $this->success([
            'result' => $result,
            'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
        ], 'WhatsApp starter template pack applied successfully.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:100'],
            'channel' => ['nullable', 'string', 'max:50'],
            'journey_type' => ['nullable', 'string', 'max:50'],
            'weather_condition' => ['nullable', 'string', 'max:50'],
            'emoji' => ['nullable', 'string', 'max:12'],
            'body' => ['required', 'string', 'max:1024'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'category' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:20'],
        ]);

        $template = $this->whatsAppTemplateService->create($validated);

        return $this->success([
            'template' => $template,
            'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
        ], 'WhatsApp template created successfully.', 201);
    }

    public function update(Request $request, CasMessageTemplate $template)
    {
        $validated = $request->validate([
            'key' => ['sometimes', 'required', 'string', 'max:100'],
            'channel' => ['nullable', 'string', 'max:50'],
            'journey_type' => ['nullable', 'string', 'max:50'],
            'weather_condition' => ['nullable', 'string', 'max:50'],
            'emoji' => ['nullable', 'string', 'max:12'],
            'body' => ['sometimes', 'required', 'string', 'max:1024'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'category' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:20'],
        ]);

        $updated = $this->whatsAppTemplateService->update($template, $validated);

        return $this->success([
            'template' => $updated,
            'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
        ], 'WhatsApp template updated successfully.');
    }

    public function submit(CasMessageTemplate $template)
    {
        $updated = $this->whatsAppTemplateService->submitForApproval($template);

        return $this->success([
            'template' => $updated,
            'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
        ], 'Template submitted for approval.');
    }

    public function simulateApproval(Request $request, CasMessageTemplate $template)
    {
        $validated = $request->validate([
            'approval_status' => ['required', 'in:approved,rejected'],
            'approval_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->whatsAppTemplateService->simulateApproval(
            $template,
            $validated['approval_status'],
            $validated['approval_notes'] ?? null,
        );

        return $this->success([
            'template' => $updated,
            'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
        ], 'Template approval status updated.');
    }
}
