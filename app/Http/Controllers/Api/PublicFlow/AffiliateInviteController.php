<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Affiliate\AffiliateTrackingService;
use Illuminate\Http\Request;

class AffiliateInviteController extends BaseController
{
    public function __construct(private readonly AffiliateTrackingService $affiliateTrackingService)
    {
    }

    public function show(Request $request, string $shareCode)
    {
        $trackClick = $request->boolean('track', true);
        $payload = $this->affiliateTrackingService->invitePayloadByCode($shareCode, $trackClick);

        if (! $payload) {
            return $this->error('Affiliate invite not found.', 404);
        }

        return $this->success($payload);
    }
}
