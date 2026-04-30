<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\SimulateWhatsAppTemplateApprovalRequest;
use App\Http\Requests\Admin\StarterPackWhatsAppTemplateRequest;
use App\Http\Requests\Admin\StoreWhatsAppTemplateRequest;
use App\Http\Requests\Admin\UpdateWhatsAppTemplateRequest;
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

    public function starterPack(StarterPackWhatsAppTemplateRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $result = $this->whatsAppTemplateService->installStarterPack((bool) ($validated['overwrite_existing'] ?? false));
            $dashboard = $this->whatsAppTemplateService->dashboardPayload();

            $data = [
                    'result' => $result,
                    'dashboard' => $dashboard,
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

    public function store(StoreWhatsAppTemplateRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $template = $this->whatsAppTemplateService->create($validated);
            $dashboard = $this->whatsAppTemplateService->dashboardPayload();

            $data = [
                    'template' => $template,
                    'dashboard' => $dashboard,
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

    public function update(UpdateWhatsAppTemplateRequest $request, CasMessageTemplate $template)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $updated = $this->whatsAppTemplateService->update($template, $validated);
            $dashboard = $this->whatsAppTemplateService->dashboardPayload();

            $data = [
                    'template' => $updated,
                    'dashboard' => $dashboard,
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
            $dashboard = $this->whatsAppTemplateService->dashboardPayload();

            $data = [
                    'template' => $updated,
                    'dashboard' => $dashboard,
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

    public function simulateApproval(SimulateWhatsAppTemplateApprovalRequest $request, CasMessageTemplate $template)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $updated = $this->whatsAppTemplateService->simulateApproval(
                $template,
                $validated['approval_status'],
                $validated['approval_notes'] ?? null,
            );
            $dashboard = $this->whatsAppTemplateService->dashboardPayload();

            $data = [
                    'template' => $updated,
                    'dashboard' => $dashboard,
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
