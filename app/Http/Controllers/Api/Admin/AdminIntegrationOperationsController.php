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
        return $this->success($this->integrationPlaybookService->payload());
    }
}
