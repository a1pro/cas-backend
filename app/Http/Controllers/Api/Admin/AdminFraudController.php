<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\BlockFraudUserRequest;
use App\Http\Requests\Admin\ReviewFraudUserRequest;
use App\Http\Requests\Admin\UnblockFraudUserRequest;
use App\Models\User;
use App\Services\Fraud\FraudPreventionService;
use Illuminate\Support\Facades\DB;

class AdminFraudController extends BaseController
{
    public function __construct(private readonly FraudPreventionService $fraudPreventionService)
    {
    }

    public function index()
    {
        try {
            $users = $this->fraudPreventionService->flaggedUsers();
            $summary = $this->fraudPreventionService->dashboardSummary();
            $userPayloads = $users->map(fn (User $user) => $this->fraudPreventionService->userPayload($user))->values();

            $data = [
                    'summary' => $summary,
                    'users' => $userPayloads,
                ];

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

    public function review(ReviewFraudUserRequest $request, User $user)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $user = $this->fraudPreventionService->markReviewed($user, $validated['notes'] ?? null);

            $data = $this->fraudPreventionService->userPayload($user);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'User marked as reviewed.',
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

    public function block(BlockFraudUserRequest $request, User $user)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $user = $this->fraudPreventionService->blockUser($user, $validated['reason'] ?? null, $validated['days'] ?? null);

            $data = $this->fraudPreventionService->userPayload($user);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'User blocked successfully.',
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

    public function unblock(UnblockFraudUserRequest $request, User $user)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $user = $this->fraudPreventionService->unblockUser($user, $validated['notes'] ?? null);

            $data = $this->fraudPreventionService->userPayload($user);

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'User unblocked successfully.',
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
