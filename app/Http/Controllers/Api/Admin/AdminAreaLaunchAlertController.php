<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Services\Notifications\AreaLaunchAlertService;
use Illuminate\Http\Request;

class AdminAreaLaunchAlertController extends BaseController
{
    public function __construct(private readonly AreaLaunchAlertService $areaLaunchAlertService)
    {
    }

    public function index(Request $request)
    {
        $limit = (int) $request->integer('limit', 8);

        return $this->success($this->areaLaunchAlertService->dashboardPayload($limit));
    }

    public function preview(Merchant $merchant)
    {
        return $this->success([
            'preview' => $this->areaLaunchAlertService->previewForMerchant($merchant),
        ]);
    }

    public function send(Request $request, Merchant $merchant)
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->success(
            $this->areaLaunchAlertService->triggerForMerchant(
                $merchant->fresh(['venues', 'wallet']),
                $request->user(),
                'manual_admin',
                $validated['notes'] ?? null,
            ),
            'Area launch alert processed successfully.'
        );
    }
}
