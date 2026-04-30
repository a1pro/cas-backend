<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\PublicFlow\StoreLeadGeneratorRequest;
use App\Services\Lead\LeadGeneratorService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class LeadGeneratorController extends BaseController
{
    public function __construct(private readonly LeadGeneratorService $leadGeneratorService)
    {
    }

    public function store(StoreLeadGeneratorRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $validated['postcode'] = strtoupper(trim($validated['postcode']));
            $lead = $this->leadGeneratorService->create($request->user(), $validated);
            $leadPayload = $this->leadGeneratorService->payload($lead);

            $data = [
                    'lead' => $leadPayload,
                    'next_step' => $lead->matched_merchant_id
                        ? 'Your request has been linked to a nearby merchant area so the team can follow up faster.'
                        : 'Your request has been added to the TALK to CAS waiting list for this area.',
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Lead request saved successfully.',
                'data' => $data,
            ], 201);
        
        } catch (ValidationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 422,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'status_code' => 404,
                'message' => 'Resource not found.',
            ], 404);
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
