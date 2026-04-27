<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Merchant;
use App\Models\Venue;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminInformationController extends BaseController
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:all,pending,approved,rejected'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:300'],
        ]);

        $query = Venue::with(['merchant.user']);

        if (($validated['status'] ?? 'all') !== 'all') {
            $query->where('approval_status', $validated['status']);
        }

        if (! empty($validated['merchant_id'])) {
            $query->where('merchant_id', $validated['merchant_id']);
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($nested) use ($search) {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('postcode', 'like', "%{$search}%")
                    ->orWhere('venue_code', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhereHas('merchant', fn ($merchantQuery) => $merchantQuery->where('business_name', 'like', "%{$search}%"));
            });
        }

        $venues = $query
            ->latest('submitted_for_approval_at')
            ->latest('created_at')
            ->limit((int) ($validated['limit'] ?? 100))
            ->get();

        return $this->success([
            'summary' => $this->summary(),
            'items' => $venues->map(fn (Venue $venue) => $this->transformVenue($venue))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateVenuePayload($request, true);
        $merchant = Merchant::with('user')->findOrFail($validated['merchant_id']);
        $status = $validated['approval_status'] ?? 'approved';
        $manualCode = $this->normaliseVenueCode($validated['venue_code'] ?? null);

        if ($status === 'approved' && ($merchant->status !== 'active' || ! $merchant->user?->is_active)) {
            return $this->error('Choose an approved merchant before creating an approved venue, or set this venue to pending.', 422);
        }

        if ($status === 'approved' && ! $manualCode) {
            return $this->error('Enter a 6 character alphanumeric venue code before approving this venue.', 422);
        }

        $venue = $merchant->venues()->create(array_merge(
            $this->normalisedVenuePayload($validated),
            $this->approvalFields($status, $request->user()->id, null, $manualCode)
        ));

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh(['merchant.user'])),
            'summary' => $this->summary(),
        ], $status === 'approved' ? 'Venue information created and manual 6 character code saved.' : 'Venue information created.', 201);
    }

    public function update(Request $request, Venue $venue)
    {
        $validated = $this->validateVenuePayload($request, false, $venue);
        $status = $validated['approval_status'] ?? $venue->approval_status ?? 'pending';
        $manualCode = $this->normaliseVenueCode($validated['venue_code'] ?? null);

        if (! empty($validated['merchant_id']) && (int) $validated['merchant_id'] !== (int) $venue->merchant_id) {
            $venue->merchant_id = (int) $validated['merchant_id'];
        }

        $merchant = Merchant::with('user')->findOrFail((int) ($validated['merchant_id'] ?? $venue->merchant_id));
        if ($status === 'approved' && ($merchant->status !== 'active' || ! $merchant->user?->is_active)) {
            return $this->error('Approve the merchant account before approving this venue.', 422);
        }

        if ($status === 'approved' && ! $manualCode && ! $venue->venue_code) {
            return $this->error('Enter a 6 character alphanumeric venue code before approving this venue.', 422);
        }

        $venue->fill($this->normalisedVenuePayload($validated, $venue));
        $venue->fill($this->approvalFields($status, $request->user()->id, $venue, $manualCode));
        $venue->save();

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh(['merchant.user'])),
            'summary' => $this->summary(),
        ], 'Venue information updated successfully.');
    }

    public function destroy(Venue $venue)
    {
        if ($venue->vouchers()->exists()) {
            return $this->error('This venue has voucher history and cannot be deleted. Deactivate or reject it instead.', 422);
        }

        $venue->delete();

        return $this->success([
            'summary' => $this->summary(),
        ], 'Venue information deleted successfully.');
    }

    public function approve(Request $request, Venue $venue)
    {
        $venue->load('merchant.user');

        if ($venue->merchant?->status !== 'active' || ! $venue->merchant?->user?->is_active) {
            return $this->error('Approve the merchant account before approving venues.', 422);
        }

        $validated = $request->validate([
            'venue_code' => $this->venueCodeRules($venue, true),
        ]);

        $venue->update($this->approvalFields(
            'approved',
            $request->user()->id,
            $venue,
            $this->normaliseVenueCode($validated['venue_code'] ?? null)
        ));

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh(['merchant.user'])),
            'summary' => $this->summary(),
        ], 'Venue approved with manual 6 character alphanumeric code.');
    }

    public function publish(Request $request, Venue $venue)
    {
        return $this->approve($request, $venue);
    }

    public function reject(Request $request, Venue $venue)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $venue->update([
            'approval_status' => 'rejected',
            'is_active' => false,
            'approved_at' => null,
            'approved_by_user_id' => null,
            'rejected_at' => now(),
            'rejection_reason' => $validated['reason'] ?? 'Rejected from admin information page.',
        ]);

        return $this->success([
            'venue' => $this->transformVenue($venue->fresh(['merchant.user'])),
            'summary' => $this->summary(),
        ], 'Venue rejected successfully.');
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'format' => ['nullable', 'in:csv,xls,excel'],
            'status' => ['nullable', 'in:all,pending,approved,rejected'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
        ]);

        $rows = Venue::with(['merchant'])
            ->when(($validated['status'] ?? 'all') !== 'all', fn ($query) => $query->where('approval_status', $validated['status']))
            ->when(! empty($validated['merchant_id']), fn ($query) => $query->where('merchant_id', $validated['merchant_id']))
            ->latest('created_at')
            ->get()
            ->map(fn (Venue $venue) => $this->exportRow($venue))
            ->values()
            ->all();

        $format = $validated['format'] ?? 'csv';

        if (in_array($format, ['xls', 'excel'], true)) {
            return $this->excelResponse($rows);
        }

        return $this->csvResponse($rows);
    }

    private function validateVenuePayload(Request $request, bool $creating, ?Venue $venue = null): array
    {
        return $request->validate([
            'merchant_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:merchants,id'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'category' => [$creating ? 'required' : 'sometimes', 'in:club,bar,restaurant,takeaway,cafe'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => [$creating ? 'required' : 'sometimes', 'string', 'max:16'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:1200'],
            'promo_message' => ['nullable', 'string', 'max:500'],
            'approval_status' => ['nullable', 'in:pending,approved,rejected'],
            'venue_code' => $this->venueCodeRules($venue, false),
            'offer_type' => ['nullable', 'in:ride,food,dual_choice,dual'],
            'ride_trip_type' => ['nullable', 'in:to_venue,to_and_from'],
            'offer_value' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'minimum_order' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ]);
    }

    private function normalisedVenuePayload(array $validated, ?Venue $venue = null): array
    {
        $category = $validated['category'] ?? $venue?->category ?? 'restaurant';
        $offerType = $validated['offer_type'] ?? $venue?->offer_type ?? ($this->isFoodBusiness($category) ? 'food' : 'ride');

        return [
            'name' => array_key_exists('name', $validated) ? trim($validated['name']) : $venue?->name,
            'category' => $category,
            'address' => array_key_exists('address', $validated) ? ($validated['address'] ?? null) : $venue?->address,
            'city' => array_key_exists('city', $validated) ? ($validated['city'] ?? null) : $venue?->city,
            'postcode' => array_key_exists('postcode', $validated) ? strtoupper(trim($validated['postcode'])) : $venue?->postcode,
            'latitude' => array_key_exists('latitude', $validated) ? ($validated['latitude'] ?? null) : $venue?->latitude,
            'longitude' => array_key_exists('longitude', $validated) ? ($validated['longitude'] ?? null) : $venue?->longitude,
            'description' => array_key_exists('description', $validated) ? ($validated['description'] ?? null) : $venue?->description,
            'promo_message' => array_key_exists('promo_message', $validated) ? ($validated['promo_message'] ?? null) : $venue?->promo_message,
            'offer_type' => $offerType === 'dual' ? 'dual_choice' : $offerType,
            'ride_trip_type' => $validated['ride_trip_type'] ?? $venue?->ride_trip_type ?? 'to_venue',
            'offer_value' => $validated['offer_value'] ?? $venue?->offer_value ?? 5,
            'minimum_order' => array_key_exists('minimum_order', $validated) ? ($validated['minimum_order'] ?? null) : ($venue?->minimum_order ?? ($this->isFoodBusiness($category) ? 25 : null)),
            'offer_enabled' => $venue?->offer_enabled ?? false,
            'offer_days' => $venue?->offer_days ?? ['friday', 'saturday'],
            'offer_start_time' => $venue?->offer_start_time ?? '18:00:00',
            'offer_end_time' => $venue?->offer_end_time ?? '23:59:00',
            'fulfilment_type' => $venue?->fulfilment_type ?? ($this->isFoodBusiness($category) ? 'delivery' : 'venue'),
            'offer_review_status' => $venue?->offer_review_status ?? 'draft',
        ];
    }

    private function approvalFields(string $status, int $adminUserId, ?Venue $venue = null, ?string $manualCode = null): array
    {
        if ($status === 'approved') {
            return [
                'approval_status' => 'approved',
                'venue_code' => $manualCode ?: $venue?->venue_code,
                'is_active' => true,
                'submitted_for_approval_at' => $venue?->submitted_for_approval_at ?: now(),
                'approved_at' => now(),
                'approved_by_user_id' => $adminUserId,
                'rejected_at' => null,
                'rejection_reason' => null,
            ];
        }

        if ($status === 'rejected') {
            return [
                'approval_status' => 'rejected',
                'is_active' => false,
                'submitted_for_approval_at' => $venue?->submitted_for_approval_at ?: now(),
                'approved_at' => null,
                'approved_by_user_id' => null,
                'rejected_at' => now(),
                'rejection_reason' => $venue?->rejection_reason ?: 'Rejected by admin.',
            ];
        }

        return [
            'approval_status' => 'pending',
            'is_active' => false,
            'submitted_for_approval_at' => $venue?->submitted_for_approval_at ?: now(),
            'approved_at' => null,
            'approved_by_user_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ];
    }

    private function summary(): array
    {
        return [
            'pending' => Venue::where('approval_status', 'pending')->count(),
            'approved' => Venue::where('approval_status', 'approved')->count(),
            'rejected' => Venue::where('approval_status', 'rejected')->count(),
            'total' => Venue::count(),
        ];
    }

    private function transformVenue(Venue $venue): array
    {
        return [
            'id' => $venue->id,
            'merchant_id' => $venue->merchant_id,
            'name' => $venue->name,
            'category' => $venue->category,
            'address' => $venue->address,
            'city' => $venue->city,
            'postcode' => $venue->postcode,
            'latitude' => $venue->latitude !== null ? (float) $venue->latitude : null,
            'longitude' => $venue->longitude !== null ? (float) $venue->longitude : null,
            'description' => $venue->description,
            'promo_message' => $venue->promo_message,
            'is_active' => (bool) $venue->is_active,
            'approval_status' => $venue->approval_status ?: ((bool) $venue->is_active ? 'approved' : 'pending'),
            'venue_code' => $venue->venue_code,
            'submitted_for_approval_at' => optional($venue->submitted_for_approval_at)?->toIso8601String(),
            'approved_at' => optional($venue->approved_at)?->toIso8601String(),
            'rejected_at' => optional($venue->rejected_at)?->toIso8601String(),
            'rejection_reason' => $venue->rejection_reason,
            'offer_type' => $venue->offer_type,
            'ride_trip_type' => $venue->ride_trip_type,
            'offer_value' => $venue->offer_value !== null ? number_format((float) $venue->offer_value, 2, '.', '') : null,
            'minimum_order' => $venue->minimum_order !== null ? number_format((float) $venue->minimum_order, 2, '.', '') : null,
            'merchant' => $venue->merchant ? [
                'id' => $venue->merchant->id,
                'business_name' => $venue->merchant->business_name,
                'business_type' => $venue->merchant->business_type,
                'status' => $venue->merchant->status,
                'contact_email' => $venue->merchant->contact_email,
            ] : null,
            'approved_by' => $this->transformApprover($venue),
        ];
    }


    private function transformApprover(Venue $venue): ?array
    {
        if (! $venue->approved_by_user_id) {
            return null;
        }

        $approver = User::query()->select(['id', 'name'])->find($venue->approved_by_user_id);

        if (! $approver) {
            return [
                'id' => $venue->approved_by_user_id,
                'name' => 'Admin user #' . $venue->approved_by_user_id,
            ];
        }

        return [
            'id' => $approver->id,
            'name' => $approver->name,
        ];
    }

    private function approverName(Venue $venue): ?string
    {
        if (! $venue->approved_by_user_id) {
            return null;
        }

        $approver = User::query()->select(['id', 'name'])->find($venue->approved_by_user_id);

        return $approver?->name ?: 'Admin user #' . $venue->approved_by_user_id;
    }

    private function exportRow(Venue $venue): array
    {
        return [
            'ID' => $venue->id,
            'Venue Code' => $venue->venue_code,
            'Status' => $venue->approval_status ?: ((bool) $venue->is_active ? 'approved' : 'pending'),
            'Merchant' => $venue->merchant?->business_name,
            'Business Type' => $venue->merchant?->business_type,
            'Venue Name' => $venue->name,
            'Venue Category' => $venue->category,
            'Address' => $venue->address,
            'City' => $venue->city,
            'Postcode' => $venue->postcode,
            'Latitude' => $venue->latitude,
            'Longitude' => $venue->longitude,
            'Information / Description' => $venue->description,
            'Promo Message' => $venue->promo_message,
            'Offer Type' => $venue->offer_type,
            'Ride Trip Type' => $venue->ride_trip_type,
            'Offer Value' => $venue->offer_value,
            'Minimum Order' => $venue->minimum_order,
            'Active' => (bool) $venue->is_active ? 'yes' : 'no',
            'Submitted At' => optional($venue->submitted_for_approval_at)?->toDateTimeString(),
            'Approved At' => optional($venue->approved_at)?->toDateTimeString(),
            'Approved By' => $this->approverName($venue),
            'Rejected At' => optional($venue->rejected_at)?->toDateTimeString(),
            'Rejection Reason' => $venue->rejection_reason,
            'Created At' => optional($venue->created_at)?->toDateTimeString(),
        ];
    }

    private function csvResponse(array $rows): StreamedResponse
    {
        $filename = 'venue-information-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            $headers = $rows[0] ?? $this->emptyExportRow();
            fputcsv($handle, array_keys($headers));
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function excelResponse(array $rows): StreamedResponse
    {
        $filename = 'venue-information-' . now()->format('Ymd-His') . '.xls';

        return response()->streamDownload(function () use ($rows): void {
            $headers = $rows[0] ?? $this->emptyExportRow();
            echo '<?xml version="1.0"?>' . "\n";
            echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Venue Information"><Table>';
            echo '<Row>';
            foreach (array_keys($headers) as $header) {
                echo '<Cell><Data ss:Type="String">' . e($header) . '</Data></Cell>';
            }
            echo '</Row>';
            foreach ($rows as $row) {
                echo '<Row>';
                foreach ($row as $value) {
                    echo '<Cell><Data ss:Type="String">' . e((string) $value) . '</Data></Cell>';
                }
                echo '</Row>';
            }
            echo '</Table></Worksheet></Workbook>';
        }, $filename, ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    private function emptyExportRow(): array
    {
        return [
            'ID' => null,
            'Venue Code' => null,
            'Status' => null,
            'Merchant' => null,
            'Business Type' => null,
            'Venue Name' => null,
            'Venue Category' => null,
            'Address' => null,
            'City' => null,
            'Postcode' => null,
            'Latitude' => null,
            'Longitude' => null,
            'Information / Description' => null,
            'Promo Message' => null,
            'Offer Type' => null,
            'Ride Trip Type' => null,
            'Offer Value' => null,
            'Minimum Order' => null,
            'Active' => null,
            'Submitted At' => null,
            'Approved At' => null,
            'Approved By' => null,
            'Rejected At' => null,
            'Rejection Reason' => null,
            'Created At' => null,
        ];
    }

    private function venueCodeRules(?Venue $venue = null, bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'size:6',
            'regex:/^[A-Za-z0-9]{6}$/',
            Rule::unique('venues', 'venue_code')->ignore($venue?->id),
        ];
    }

    private function normaliseVenueCode(?string $code): ?string
    {
        $normalised = strtoupper(trim((string) $code));

        return $normalised !== '' ? $normalised : null;
    }

    private function isFoodBusiness(?string $businessType): bool
    {
        return in_array($businessType, ['restaurant', 'takeaway', 'cafe'], true);
    }
}
