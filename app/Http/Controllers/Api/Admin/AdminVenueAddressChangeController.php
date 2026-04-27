<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\VenueAddressChangeRequest;
use App\Services\Merchant\VenueAddressApprovalService;
use Illuminate\Http\Request;

class AdminVenueAddressChangeController extends BaseController
{
    public function __construct(
        private readonly VenueAddressApprovalService $addressApprovalService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:all,pending,approved,rejected,superseded'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->success([
            'summary' => $this->addressApprovalService->adminDashboardPayload()['summary'],
            'items' => $this->addressApprovalService->listPayloads(
                $validated['status'] ?? 'pending',
                (int) ($validated['limit'] ?? 25)
            ),
        ]);
    }

    public function approve(Request $request, VenueAddressChangeRequest $venueAddressChangeRequest)
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($venueAddressChangeRequest->status !== 'pending') {
            return $this->error('Only pending address change requests can be approved.', 422);
        }

        $record = $this->addressApprovalService->approve($venueAddressChangeRequest, $validated['admin_notes'] ?? null);

        return $this->success([
            'request' => $this->addressApprovalService->payload($record),
            'dashboard' => $this->addressApprovalService->adminDashboardPayload(),
        ], 'Venue address change approved successfully.');
    }

    public function reject(Request $request, VenueAddressChangeRequest $venueAddressChangeRequest)
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($venueAddressChangeRequest->status !== 'pending') {
            return $this->error('Only pending address change requests can be rejected.', 422);
        }

        $record = $this->addressApprovalService->reject($venueAddressChangeRequest, $validated['admin_notes'] ?? null);

        return $this->success([
            'request' => $this->addressApprovalService->payload($record),
            'dashboard' => $this->addressApprovalService->adminDashboardPayload(),
        ], 'Venue address change rejected successfully.');
    }
}
