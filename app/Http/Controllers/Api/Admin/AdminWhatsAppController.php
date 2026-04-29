<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\CasMessageTemplate;
use App\Services\WhatsApp\WhatsAppTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminWhatsAppController extends BaseController
{
    public function __construct(private readonly WhatsAppTemplateService $whatsAppTemplateService)
    {
    }

    public function index()
    {
        try {
            $data = $this->whatsAppTemplateService->dashboardPayload();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function starterPack(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'overwrite_existing' => ['nullable', 'boolean'],
            ]);

            $result = $this->whatsAppTemplateService->installStarterPack((bool) ($validated['overwrite_existing'] ?? false));

            $data = [
                    'result' => $result,
                    'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'WhatsApp starter template pack applied successfully.',
                'data' => $data,
            ], 200);
        
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

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

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

            $data = [
                    'template' => $template,
                    'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'WhatsApp template created successfully.',
                'data' => $data,
            ], 201);
        
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

    public function update(Request $request, CasMessageTemplate $template)
    {
        try {
            DB::beginTransaction();

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

            $data = [
                    'template' => $updated,
                    'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'WhatsApp template updated successfully.',
                'data' => $data,
            ], 200);
        
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

    public function submit(CasMessageTemplate $template)
    {
        try {
            DB::beginTransaction();

            $updated = $this->whatsAppTemplateService->submitForApproval($template);

            $data = [
                    'template' => $updated,
                    'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Template submitted for approval.',
                'data' => $data,
            ], 200);
        
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

    public function simulateApproval(Request $request, CasMessageTemplate $template)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'approval_status' => ['required', 'in:approved,rejected'],
                'approval_notes' => ['nullable', 'string', 'max:500'],
            ]);

            $updated = $this->whatsAppTemplateService->simulateApproval(
                $template,
                $validated['approval_status'],
                $validated['approval_notes'] ?? null,
            );

            $data = [
                    'template' => $updated,
                    'dashboard' => $this->whatsAppTemplateService->dashboardPayload(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Template approval status updated.',
                'data' => $data,
            ], 200);
        
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
