<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Services\Notifications\MerchantNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MerchantApplicationController extends BaseController
{
    public function __construct(private readonly MerchantNotificationService $merchantNotificationService)
    {
    }

    public function index()
    {
        return $this->success(
            Merchant::with(['user', 'wallet', 'venues'])->latest()->get()
        );
    }

    public function approve(Merchant $merchant)
    {
        DB::transaction(function () use ($merchant) {
            $merchant->update([
                'status' => 'active',
                'approved_at' => now(),
                'rejected_at' => null,
            ]);
            $merchant->user()->update(['is_active' => true]);
            $merchant->venues()->update(['is_active' => true]);
        });

        $this->merchantNotificationService->sendApprovalMail($merchant->fresh('user'));

        return $this->success($merchant->fresh(['user', 'wallet', 'venues']), 'Merchant approved successfully');
    }

    public function reject(Merchant $merchant, Request $request)
    {
        DB::transaction(function () use ($merchant, $request) {
            $merchant->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'approval_notes' => $request->string('notes')->toString() ?: 'Rejected by admin',
            ]);
            $merchant->user()->update(['is_active' => false]);
            $merchant->venues()->update(['is_active' => false]);
        });

        return $this->success($merchant->fresh(['user', 'wallet', 'venues']), 'Merchant rejected');
    }
}
