<?php

namespace App\Http\Controllers\Api\PublicFlow;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\PublicFlow\CreateVenueTagRequest;
use App\Services\Tag\TagButtonService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class TagButtonController extends BaseController
{
    public function __construct(private readonly TagButtonService $tagButtonService)
    {
    }

    public function create(CreateVenueTagRequest $request)
    {
        try {
            DB::beginTransaction();

            $validated = $request->validated();

            $tag = $this->tagButtonService->create([
                ...$validated,
                'source_channel' => $validated['source_channel'] ?? 'website',
                'metadata' => [
                    'created_from' => 'public_tag_form',
                    'request_ip' => $request->ip(),
                ],
            ]);
            $tagPayload = $this->tagButtonService->toPublicPayload($tag);

            $data = [
                    'tag' => $tagPayload,
                ];

            DB::commit();

            return response()->json([
                'success' => true,
                'status_code' => 201,
                'message' => 'Venue tag created successfully',
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

    public function show(string $shareCode)
    {
        try {
            $tag = $this->tagButtonService->findPublic($shareCode);

            if (! $tag) {
                return response()->json([
                    'success' => false,
                    'status_code' => 404,
                    'message' => 'Venue tag not found.',
                ], 404);
            }

            $tagPayload = $this->tagButtonService->toPublicPayload($tag);

            $data = [
                    'tag' => $tagPayload,
                ];

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

    public function resend(string $shareCode)
    {
        try {
            $tag = $this->tagButtonService->resendInvite($shareCode);

            if (! $tag) {
                return response()->json([
                    'success' => false,
                    'status_code' => 404,
                    'message' => 'Venue tag not found.',
                ], 404);
            }

            if ($tag->status === 'expired') {
                return response()->json([
                    'success' => false,
                    'status_code' => 422,
                    'message' => 'This venue tag has expired and cannot be re-sent.',
                ], 422);
            }

            $tagPayload = $this->tagButtonService->toPublicPayload($tag);

            $data = [
                    'tag' => $tagPayload,
                ];

            return response()->json([
                'success' => true,
                'status_code' => 200,
                'message' => 'Venue invite notifications sent',
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
