<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Services\Operations\IntegrationPlaybookService;

class AdminIntegrationOperationsController extends BaseController
{
    public function __construct(private readonly IntegrationPlaybookService $integrationPlaybookService)
    {
    }

    public function show()
    {
        try {
            $data = $this->integrationPlaybookService->payload();

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
}
