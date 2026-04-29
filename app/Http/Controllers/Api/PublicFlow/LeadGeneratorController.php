<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Lead\LeadGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class LeadGeneratorController extends BaseController
{
    public function __construct(private readonly LeadGeneratorService $leadGeneratorService)
    {
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validate([
                'customer_name' => ['required', 'string', 'max:255'],
                'customer_email' => ['nullable', 'email', 'max:255'],
                'customer_phone' => ['nullable', 'string', 'max:50'],
                'postcode' => ['required', 'string', 'max:16'],
                'city' => ['nullable', 'string', 'max:120'],
                'journey_type' => ['required', 'in:nightlife,food'],
                'flow_type' => ['nullable', 'in:going_out,order_food'],
                'desired_venue_name' => ['nullable', 'string', 'max:255'],
                'desired_category' => ['nullable', 'string', 'max:80'],
                'source' => ['nullable', 'in:discovery_no_results,waiting_list,manual,tag_missing_venue'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'utm_source' => ['nullable', 'string', 'max:120'],
                'submitted_from' => ['nullable', 'string', 'max:120'],
                'contact_consent' => ['required', 'boolean'],
            ]);

            if (blank($validated['customer_email'] ?? null) && blank($validated['customer_phone'] ?? null)) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'Enter at least an email address or phone number so TALK to CAS can follow up.',
                    'errors' => [
                            'contact' => ['Email or phone is required.'],
                        ],
                ], 422);
            }

            if (! (bool) $validated['contact_consent']) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'You must agree to be contacted before joining the waiting list.',
                    'errors' => [
                            'contact_consent' => ['Contact consent is required.'],
                        ],
                ], 422);
            }

            $validated['postcode'] = strtoupper(trim($validated['postcode']));
            $lead = $this->leadGeneratorService->create($request->user(), $validated);

            $data = [
                    'lead' => $this->leadGeneratorService->payload($lead),
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
