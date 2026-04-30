<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\PublicFlow\ShowAffiliateInviteRequest;
use App\Services\Affiliate\AffiliateTrackingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class AffiliateInviteController extends BaseController
{
    public function __construct(private readonly AffiliateTrackingService $affiliateTrackingService)
    {
    }

    public function show(ShowAffiliateInviteRequest $request, string $shareCode)
    {
        try {
            $trackClick = $request->boolean('track', true);
            $payload = $this->affiliateTrackingService->invitePayloadByCode($shareCode, $trackClick);

            if (! $payload) {
                return response()->json([
                    'success' => false,
                    'status_code' => 404,
                    'message' => 'Affiliate invite not found.',
                ], 404);
            }

            $data = $payload;

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Operation completed successfully',
                'data' => $data,
            ], 200);
        
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'status_code' => 404,
                'message' => 'Resource not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'message' => 'Something went wrong. ' . $e->getMessage(),
            ], 500);
        }
    }
}
