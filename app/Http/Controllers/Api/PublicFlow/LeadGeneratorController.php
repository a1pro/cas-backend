<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Services\Lead\LeadGeneratorService;
use Illuminate\Http\Request;

class LeadGeneratorController extends BaseController
{
    public function __construct(private readonly LeadGeneratorService $leadGeneratorService)
    {
    }

    public function store(Request $request)
    {
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
            return $this->error('Enter at least an email address or phone number so TALK to CAS can follow up.', 422, [
                'contact' => ['Email or phone is required.'],
            ]);
        }

        if (! (bool) $validated['contact_consent']) {
            return $this->error('You must agree to be contacted before joining the waiting list.', 422, [
                'contact_consent' => ['Contact consent is required.'],
            ]);
        }

        $validated['postcode'] = strtoupper(trim($validated['postcode']));
        $lead = $this->leadGeneratorService->create($request->user(), $validated);

        return $this->success([
            'lead' => $this->leadGeneratorService->payload($lead),
            'next_step' => $lead->matched_merchant_id
                ? 'Your request has been linked to a nearby merchant area so the team can follow up faster.'
                : 'Your request has been added to the TALK to CAS waiting list for this area.',
        ], 'Lead request saved successfully.', 201);
    }
}
