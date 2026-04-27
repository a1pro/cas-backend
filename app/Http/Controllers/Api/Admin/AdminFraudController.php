<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\User;
use App\Services\Fraud\FraudPreventionService;
use Illuminate\Http\Request;

class AdminFraudController extends BaseController
{
    public function __construct(private readonly FraudPreventionService $fraudPreventionService)
    {
    }

    public function index()
    {
        $users = $this->fraudPreventionService->flaggedUsers();

        return $this->success([
            'summary' => $this->fraudPreventionService->dashboardSummary(),
            'users' => $users->map(fn (User $user) => $this->fraudPreventionService->userPayload($user))->values(),
        ]);
    }

    public function review(Request $request, User $user)
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->fraudPreventionService->markReviewed($user, $validated['notes'] ?? null);

        return $this->success($this->fraudPreventionService->userPayload($user), 'User marked as reviewed.');
    }

    public function block(Request $request, User $user)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $user = $this->fraudPreventionService->blockUser($user, $validated['reason'] ?? null, $validated['days'] ?? null);

        return $this->success($this->fraudPreventionService->userPayload($user), 'User blocked successfully.');
    }

    public function unblock(Request $request, User $user)
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->fraudPreventionService->unblockUser($user, $validated['notes'] ?? null);

        return $this->success($this->fraudPreventionService->userPayload($user), 'User unblocked successfully.');
    }
}
