<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\ExportProviderVoucherLinkRequest;
use App\Http\Requests\Admin\ListProviderVoucherLinkRequest;
use App\Http\Requests\Admin\StoreProviderVoucherLinkRequest;
use App\Http\Requests\Admin\UpdateProviderVoucherLinkRequest;
use App\Http\Requests\Admin\UploadProviderVoucherLinkRequest;
use App\Http\Requests\Admin\VenueExportProviderVoucherLinkRequest;
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

    public function index(ListProviderVoucherLinkRequest $request)
    {
        try {
            $validated = $request->validated();

            if ($request->has('page') || $request->has('per_page') || $request->has('search') || $request->has('provider') || $request->has('merchant_id') || $request->has('active_only')) {
                $data = $this->providerVoucherLinkService->paginatedPayload($validated);

                return response()->json([
                    'success' => true,
                    'status_code' => 200,
                    'message' => 'Operation completed successfully',
                    'data' => $data,
                ], 200);
            }

            $summary = $this->providerVoucherLinkService->summary();
            $items = $this->providerVoucherLinkService->listPayloads((int) ($validated['limit'] ?? 25));

            $data = [
                    'summary' => $summary,
                    'items' => $items,
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

    public function export(ExportProviderVoucherLinkRequest $request)
    {
        try {
            $validated = $request->validated();

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

    public function venueExport(VenueExportProviderVoucherLinkRequest $request)
    {
        try {
            $validated = $request->validated();

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

    public function store(StoreProviderVoucherLinkRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();
            $record = $this->providerVoucherLinkService->create($validated, $request->user());
            $recordPayload = $this->providerVoucherLinkService->payload($record->fresh(['merchant', 'venue', 'creator']));
            $summary = $this->providerVoucherLinkService->summary();

            $data = [
                    'record' => $recordPayload,
                    'summary' => $summary,
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

    public function update(UpdateProviderVoucherLinkRequest $request, ProviderVoucherLink $providerVoucherLink)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();
            $record = $this->providerVoucherLinkService->update($providerVoucherLink, $validated, $request->user());
            $recordPayload = $this->providerVoucherLinkService->payload($record->fresh(['merchant', 'venue', 'creator']));
            $summary = $this->providerVoucherLinkService->summary();

            $data = [
                    'record' => $recordPayload,
                    'summary' => $summary,
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
            $summary = $this->providerVoucherLinkService->summary();

            $data = [
                    'deleted' => true,
                    'id' => $id,
                    'summary' => $summary,
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

    public function upload(UploadProviderVoucherLinkRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $records = $this->providerVoucherLinkService->importFromFile(
                $request->file('file'),
                $request->user(),
                $validated
            );
            $summary = $this->providerVoucherLinkService->summary();
            $items = collect($records)
                ->map(fn ($record) => $this->providerVoucherLinkService->payload($record->fresh(['merchant', 'venue', 'creator'])))
                ->values()
                ->all();

            $data = [
                    'count' => count($records),
                    'summary' => $summary,
                    'items' => $items,
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

}
