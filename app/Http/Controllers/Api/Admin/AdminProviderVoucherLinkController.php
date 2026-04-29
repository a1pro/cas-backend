<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\ProviderVoucherLink;
use App\Services\Voucher\ProviderVoucherLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminProviderVoucherLinkController extends BaseController
{
    public function __construct(
        private readonly ProviderVoucherLinkService $providerVoucherLinkService,
    ) {
    }

    public function index(Request $request)
    {
        try {
            $this->normalizeBooleanInput($request, 'active_only');

            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'merchant_id' => ['nullable', 'exists:merchants,id'],
                'provider' => ['nullable', 'in:uber,ubereats'],
                'active_only' => ['nullable', 'boolean'],
                'search' => ['nullable', 'string', 'max:120'],
            ]);

            if ($request->has('page') || $request->has('per_page') || $request->has('search') || $request->has('provider') || $request->has('merchant_id') || $request->has('active_only')) {
                $data = $this->providerVoucherLinkService->paginatedPayload($validated);

                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => 'Operation completed successfully',
                    'data' => $data,
                ], 200);
            }

            $data = [
                    'summary' => $this->providerVoucherLinkService->summary(),
                    'items' => $this->providerVoucherLinkService->listPayloads((int) ($validated['limit'] ?? 25)),
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

    public function template()
    {
        try {
            $data = $this->providerVoucherLinkService->importTemplatePayload();

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

    public function export(Request $request)
    {
        try {
            $this->normalizeBooleanInput($request, 'active_only');

            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
                'merchant_id' => ['nullable', 'exists:merchants,id'],
                'provider' => ['nullable', 'in:uber,ubereats'],
                'active_only' => ['nullable', 'boolean'],
                'source_label' => ['nullable', 'string', 'max:120'],
                'import_batch_code' => ['nullable', 'string', 'max:40'],
            ]);

            $data = $this->providerVoucherLinkService->exportPayload($validated);

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

    public function venueExport(Request $request)
    {
        try {
            $this->normalizeBooleanInput($request, 'missing_only');

            $validated = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
                'merchant_id' => ['nullable', 'exists:merchants,id'],
                'provider' => ['nullable', 'in:uber,ubereats'],
                'missing_only' => ['nullable', 'boolean'],
            ]);

            $data = $this->providerVoucherLinkService->venueVoucherCreationPayload($validated);

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

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $validated = $this->validateProviderVoucherLink($request);
            $record = $this->providerVoucherLinkService->create($validated, $request->user());

            $data = [
                    'record' => $this->providerVoucherLinkService->payload($record->fresh(['merchant', 'venue', 'creator'])),
                    'summary' => $this->providerVoucherLinkService->summary(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Exact provider voucher link saved successfully.',
                'data' => $data,
            ], 201);
        
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

    public function update(Request $request, ProviderVoucherLink $providerVoucherLink)
    {
        try {
            DB::beginTransaction();

            $validated = $this->validateProviderVoucherLink($request, false);
            $record = $this->providerVoucherLinkService->update($providerVoucherLink, $validated, $request->user());

            $data = [
                    'record' => $this->providerVoucherLinkService->payload($record->fresh(['merchant', 'venue', 'creator'])),
                    'summary' => $this->providerVoucherLinkService->summary(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Provider voucher link updated successfully.',
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

    public function destroy(ProviderVoucherLink $providerVoucherLink)
    {
        try {
            DB::beginTransaction();

            $id = $providerVoucherLink->id;
            $providerVoucherLink->delete();

            $data = [
                    'deleted' => true,
                    'id' => $id,
                    'summary' => $this->providerVoucherLinkService->summary(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Provider voucher link deleted successfully.',
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

    public function upload(Request $request)
    {
        try {
            DB::beginTransaction();

            if ($request->has('is_active')) {
                $request->merge([
                    'is_active' => in_array(strtolower((string) $request->input('is_active')), ['1', 'true', 'yes', 'on'], true),
                ]);
            }

            $validated = $request->validate([
                'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:4096'],
                'merchant_id' => ['nullable', 'exists:merchants,id'],
                'venue_id' => ['nullable', 'exists:venues,id'],
                'venue_code' => ['nullable', 'alpha_num', 'size:6'],
                'provider' => ['nullable', 'in:uber,ubereats'],
                'offer_type' => ['nullable', 'in:food,ride,dual_choice'],
                'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
                'voucher_amount' => ['nullable', 'numeric', 'min:0'],
                'minimum_order' => ['nullable', 'numeric', 'min:0'],
                'location_postcode' => ['nullable', 'string', 'max:16'],
                'start_time' => ['nullable', 'date_format:H:i'],
                'end_time' => ['nullable', 'date_format:H:i'],
                'valid_from' => ['nullable', 'date'],
                'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
                'is_active' => ['nullable', 'boolean'],
                'circulation_mode' => ['nullable', 'in:shared_sequence,unique_individual'],
                'max_issue_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'source_label' => ['nullable', 'string', 'max:120'],
                'notes' => ['nullable', 'string', 'max:500'],
            ]);

            $records = $this->providerVoucherLinkService->importFromFile(
                $request->file('file'),
                $request->user(),
                $validated
            );

            $data = [
                    'count' => count($records),
                    'summary' => $this->providerVoucherLinkService->summary(),
                    'items' => collect($records)
                        ->map(fn ($record) => $this->providerVoucherLinkService->payload($record->fresh(['merchant', 'venue', 'creator'])))
                        ->values()
                        ->all(),
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Exact provider voucher links uploaded successfully.',
                'data' => $data,
            ], 201);
        
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

    private function validateProviderVoucherLink(Request $request, bool $creating = true): array
    {
        $this->normalizeBooleanInput($request, 'is_active');

        return $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'venue_id' => ['nullable', 'exists:venues,id'],
            'venue_code' => ['nullable', 'alpha_num', 'size:6'],
            'provider' => [$creating ? 'required' : 'sometimes', 'in:uber,ubereats'],
            'link_url' => [$creating ? 'required' : 'sometimes', 'url', 'max:4000'],
            'offer_type' => [$creating ? 'required' : 'sometimes', 'in:food,ride,dual_choice'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'voucher_amount' => ['nullable', 'numeric', 'min:0'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'location_postcode' => ['nullable', 'string', 'max:16'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
            'circulation_mode' => ['nullable', 'in:shared_sequence,unique_individual'],
            'max_issue_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function normalizeBooleanInput(Request $request, string $key): void
    {
        if (! $request->has($key)) {
            return;
        }

        $value = $request->input($key);

        if (is_bool($value)) {
            return;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($normalized !== null) {
            $request->merge([
                $key => $normalized,
            ]);
        }
    }
}
