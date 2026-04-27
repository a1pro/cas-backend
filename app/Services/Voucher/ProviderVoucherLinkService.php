<?php

namespace App\Services\Voucher;

use App\Models\Merchant;
use App\Models\ProviderVoucherLink;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class ProviderVoucherLinkService
{
    protected const IMPORT_HEADERS = [
        'venue_code',
        'provider',
        'link_url',
        'offer_type',
        'ride_trip_type',
        'voucher_amount',
        'minimum_order',
        'location_postcode',
        'start_time',
        'end_time',
        'valid_from',
        'valid_until',
        'is_active',
        'circulation_mode',
        'max_issue_count',
        'source_label',
        'notes',
    ];

    protected const VOUCHER_CREATION_HEADERS = [
        'venue_code',
        'uber_business_reference',
        'merchant_id',
        'merchant_name',
        'venue_id',
        'venue_name',
        'provider',
        'offer_type',
        'ride_trip_type',
        'voucher_amount',
        'minimum_order',
        'location_postcode',
        'address',
        'city',
        'start_time',
        'end_time',
        'valid_from',
        'valid_until',
        'max_issue_count',
        'has_uploaded_link',
        'uploaded_link_count',
        'remaining_issue_count',
        'admin_note',
    ];

    protected const HEADER_ALIASES = [
        'venue_code' => ['venue_code', 'venue code', 'venue reference', 'venue ref', 'reference', 'reference code', 'ref', 'cas venue code', '6 digit code', '6 character code', 'uber_business_reference', 'uber business reference', 'uber reference', 'voucher reference', 'external reference', 'campaign reference', 'program reference'],
        'provider' => ['provider', 'platform', 'channel', 'source provider', 'voucher provider', 'product', 'product type', 'service', 'voucher service'],
        'link_url' => ['link_url', 'link url', 'url', 'voucher url', 'voucher link', 'html link', 'html voucher link', 'coupon link', 'deep link', 'sample voucher link', 'redemption link', 'redeem link', 'invite link', 'claim link', 'link', 'campaign link'],
        'offer_type' => ['offer_type', 'offer type', 'offer', 'offer category', 'voucher type'],
        'ride_trip_type' => ['ride_trip_type', 'ride trip type', 'trip type', 'trips', 'ride only', 'ride setting', 'journey type'],
        'voucher_amount' => ['voucher_amount', 'voucher amount', 'voucher amount £', 'amount', 'discount', 'credit', 'coupon amount'],
        'minimum_order' => ['minimum_order', 'minimum order', 'min order', 'minimum spend', 'minimum basket'],
        'location_postcode' => ['location_postcode', 'location postcode', 'postcode', 'post code', 'business postcode', 'venue postcode', 'geo postcode', 'geo-location restriction'],
        'start_time' => ['start_time', 'start time', 'from time', 'launch time'],
        'end_time' => ['end_time', 'end time', 'until time', 'close time'],
        'valid_from' => ['valid_from', 'valid from', 'start date', 'date from', 'from date', 'starts', 'available from'],
        'valid_until' => ['valid_until', 'valid until', 'end date', 'expiry', 'expires', 'date until', 'until date', 'available until'],
        'is_active' => ['is_active', 'is active', 'active', 'status', 'available', 'enabled'],
        'circulation_mode' => ['circulation_mode', 'circulation mode', 'issue mode', 'distribution mode', 'voucher mode', 'link mode'],
        'max_issue_count' => ['max_issue_count', 'max issue count', 'issue cap', 'max vouchers', 'issued cap', 'voucher cap', 'number of vouchers generated', 'number issued', 'in circulation'],
        'source_label' => ['source_label', 'source label', 'file label', 'upload label', 'batch label'],
        'notes' => ['notes', 'note', 'comment', 'comments', 'description'],
    ];

    public function create(array $payload, User $admin): ProviderVoucherLink
    {
        return ProviderVoucherLink::create($this->normalisePayload($payload, $admin, 'manual'));
    }

    public function importFromFile(UploadedFile $file, User $admin, array $defaults = []): array
    {
        $rows = $this->readImportRows($file);
        $created = [];
        $batchCode = 'PVL-' . strtoupper(Str::random(8));

        DB::transaction(function () use ($rows, $admin, $file, $defaults, $batchCode, &$created) {
            foreach ($rows as $index => $row) {
                $mapped = $this->mapImportRow($row);
                $payload = array_merge($defaults, array_filter($mapped, fn ($value) => $value !== null && $value !== ''));

                if (! Arr::get($payload, 'link_url')) {
                    throw ValidationException::withMessages([
                        'file' => ['Row ' . ($index + 2) . ' is missing the Uber / Uber Eats HTML link.'],
                    ]);
                }

                $payload['import_batch_code'] = $batchCode;
                $payload['source_label'] = Arr::get($payload, 'source_label', $file->getClientOriginalName());

                $created[] = ProviderVoucherLink::create(
                    $this->normalisePayload($payload, $admin, 'csv_upload')
                );
            }
        });

        return $created;
    }

    public function summary(): array
    {
        return [
            'total' => ProviderVoucherLink::count(),
            'active' => ProviderVoucherLink::where('is_active', true)->count(),
            'uber' => ProviderVoucherLink::where('provider', 'uber')->count(),
            'ubereats' => ProviderVoucherLink::where('provider', 'ubereats')->count(),
            'in_circulation' => ProviderVoucherLink::query()->withCount('vouchers')->get()->sum('vouchers_count'),
        ];
    }

    public function listPayloads(int $limit = 50): array
    {
        return ProviderVoucherLink::query()
            ->with(['merchant', 'venue', 'creator'])
            ->withCount('vouchers')
            ->latest()
            ->take($limit)
            ->get()
            ->map(fn (ProviderVoucherLink $link) => $this->payload($link))
            ->values()
            ->all();
    }

    public function paginatedPayload(array $filters = []): array
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? $filters['limit'] ?? 25), 100));
        $query = $this->filteredQuery($filters)
            ->with(['merchant', 'venue', 'creator'])
            ->withCount('vouchers')
            ->latest();

        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('link_url', 'like', "%{$search}%")
                    ->orWhere('venue_code_reference', 'like', "%{$search}%")
                    ->orWhere('location_postcode', 'like', "%{$search}%")
                    ->orWhere('source_label', 'like', "%{$search}%")
                    ->orWhere('import_batch_code', 'like', "%{$search}%")
                    ->orWhereHas('merchant', fn ($merchantQuery) => $merchantQuery->where('business_name', 'like', "%{$search}%"))
                    ->orWhereHas('venue', function ($venueQuery) use ($search) {
                        $venueQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('venue_code', 'like', "%{$search}%");
                    });
            });
        }

        $paginated = $query->paginate($perPage);

        return [
            'summary' => $this->summary(),
            'items' => $paginated->getCollection()->map(fn (ProviderVoucherLink $link) => $this->payload($link))->values()->all(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ];
    }

    public function update(ProviderVoucherLink $link, array $payload, User $admin): ProviderVoucherLink
    {
        $current = [
            'merchant_id' => $link->merchant_id,
            'venue_id' => $link->venue_id,
            'venue_code' => $link->venue_code_reference,
            'provider' => $link->provider,
            'link_url' => $link->link_url,
            'offer_type' => $link->offer_type,
            'ride_trip_type' => $link->ride_trip_type,
            'voucher_amount' => $link->voucher_amount,
            'minimum_order' => $link->minimum_order,
            'location_postcode' => $link->location_postcode,
            'start_time' => $this->timeString($link->start_time),
            'end_time' => $this->timeString($link->end_time),
            'valid_from' => $link->valid_from?->toDateString(),
            'valid_until' => $link->valid_until?->toDateString(),
            'is_active' => (bool) $link->is_active,
            'circulation_mode' => $link->circulation_mode,
            'max_issue_count' => $link->max_issue_count,
            'source_label' => $link->source_label,
            'notes' => $link->notes,
            'import_batch_code' => $link->import_batch_code,
        ];

        $normalised = $this->normalisePayload(array_merge($current, $payload), $admin, 'manual-admin-update');
        $normalised['created_by_user_id'] = $link->created_by_user_id ?: $admin->id;
        $normalised['source'] = $link->source ?: 'manual';
        $normalised['import_batch_code'] = $payload['import_batch_code'] ?? $link->import_batch_code;

        $link->update($normalised);

        return $link->fresh(['merchant', 'venue', 'creator']);
    }

    public function merchantSummary(?Merchant $merchant, ?Venue $venue = null): array
    {
        if (! $merchant) {
            return [
                'summary' => $this->summary(),
                'items' => [],
            ];
        }

        $baseQuery = ProviderVoucherLink::query()->where('merchant_id', $merchant->id);

        if ($venue) {
            $baseQuery->where(function ($inner) use ($venue) {
                $inner->where('venue_id', $venue->id)->orWhereNull('venue_id');
            });
        }

        $links = (clone $baseQuery)->withCount('vouchers')->latest()->take(10)->get();

        return [
            'summary' => [
                'total' => (clone $baseQuery)->count(),
                'active' => (clone $baseQuery)->where('is_active', true)->count(),
                'latest_provider' => $links->first()?->provider,
                'issued' => (clone $baseQuery)->withCount('vouchers')->get()->sum('vouchers_count'),
            ],
            'items' => $links->map(fn (ProviderVoucherLink $link) => $this->payload($link))->values()->all(),
        ];
    }



    public function importTemplatePayload(): array
    {
        $sampleRows = [
            [
                'venue_code' => 'ABC123',
                'provider' => 'uber',
                'link_url' => 'https://r.uber.com/example-ride-link',
                'offer_type' => 'ride',
                'ride_trip_type' => 'to_venue',
                'voucher_amount' => '5.00',
                'minimum_order' => '',
                'location_postcode' => 'M1 1AA',
                'start_time' => '18:00',
                'end_time' => '23:59',
                'valid_from' => now()->startOfDay()->toDateString(),
                'valid_until' => now()->addDays(7)->endOfDay()->toDateString(),
                'is_active' => 'true',
                'circulation_mode' => 'shared_sequence',
                'max_issue_count' => '50',
                'source_label' => 'uber-business-export-apr',
                'notes' => 'Sample ride offer for upload into CAS Drive / Google Sheets.',
            ],
            [
                'venue_code' => 'DEF456',
                'provider' => 'ubereats',
                'link_url' => 'https://www.ubereats.com/promo/example-food-link',
                'offer_type' => 'food',
                'ride_trip_type' => '',
                'voucher_amount' => '5.00',
                'minimum_order' => '25.00',
                'location_postcode' => 'M4 2BS',
                'start_time' => '11:00',
                'end_time' => '22:30',
                'valid_from' => now()->startOfDay()->toDateString(),
                'valid_until' => now()->addDays(7)->endOfDay()->toDateString(),
                'is_active' => 'true',
                'circulation_mode' => 'unique_individual',
                'max_issue_count' => '200',
                'source_label' => 'ubereats-export-apr',
                'notes' => 'Sample food offer ready for Google Sheets roundtrip import.',
            ],
        ];

        return [
            'headers' => self::IMPORT_HEADERS,
            'rows' => $sampleRows,
            'csv' => $this->csvFromRows(self::IMPORT_HEADERS, $sampleRows),
            'tsv' => $this->tsvFromRows(self::IMPORT_HEADERS, $sampleRows),
            'instructions' => [
                'Download the template CSV or paste the TSV into Google Sheets.',
                'Use venue_code from the approved venue export as the row reference. CAS will assign each uploaded Uber / Uber Eats link to that venue automatically.',
                'Keep the same header row so CAS can import the sheet or Drive export back without remapping.',
                'Use max_issue_count to mirror the exact number of vouchers paid for in Uber for Business.',
            ],
        ];
    }

    public function exportPayload(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 250), 1000));
        $query = $this->filteredQuery($filters)
            ->with(['merchant', 'venue', 'creator'])
            ->withCount('vouchers')
            ->latest();

        $links = $query->take($limit)->get();
        $rows = $links->map(fn (ProviderVoucherLink $link) => $this->exportRow($link))->values()->all();
        $headers = self::IMPORT_HEADERS;
        $merchantId = Arr::get($filters, 'merchant_id');
        $provider = Arr::get($filters, 'provider');
        $fileLabelParts = array_filter([
            'cas-drive-export',
            $merchantId ? 'merchant-' . $merchantId : null,
            $provider ?: null,
            now()->format('Ymd-His'),
        ]);

        return [
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'merchant_id' => $merchantId ? (int) $merchantId : null,
                'provider' => $provider ?: null,
                'active_only' => (bool) Arr::get($filters, 'active_only', false),
                'source_label' => Arr::get($filters, 'source_label'),
                'import_batch_code' => Arr::get($filters, 'import_batch_code'),
                'limit' => $limit,
            ],
            'headers' => $headers,
            'rows' => $rows,
            'csv' => $this->csvFromRows($headers, $rows),
            'tsv' => $this->tsvFromRows($headers, $rows),
            'sheet_tab_name' => 'CAS Voucher Links',
            'suggested_filename' => implode('-', $fileLabelParts) . '.csv',
            'batch_summary' => $this->batchSummary(8, $filters),
        ];
    }

    public function venueVoucherCreationPayload(array $filters = []): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 500), 2000));
        $providerFilter = Arr::get($filters, 'provider');
        $merchantId = Arr::get($filters, 'merchant_id');
        $missingOnly = filter_var(Arr::get($filters, 'missing_only', false), FILTER_VALIDATE_BOOLEAN);

        $query = Venue::query()
            ->with(['merchant', 'providerVoucherLinks' => function ($query) {
                $query->where('is_active', true)->withCount('vouchers');
            }])
            ->where('approval_status', 'approved')
            ->where('is_active', true)
            ->whereNotNull('venue_code')
            ->where('venue_code', '<>', '')
            ->orderBy('name');

        if ($merchantId) {
            $query->where('merchant_id', (int) $merchantId);
        }

        $rows = [];
        $venueCount = 0;

        $query->chunk(200, function ($venues) use (&$rows, &$venueCount, $providerFilter, $missingOnly, $limit) {
            foreach ($venues as $venue) {
                $venueRows = $this->voucherCreationRowsForVenue($venue);
                $venueCount++;

                foreach ($venueRows as $row) {
                    if ($providerFilter && $row['provider'] !== $providerFilter) {
                        continue;
                    }

                    if ($missingOnly && $row['has_uploaded_link'] === 'yes') {
                        continue;
                    }

                    $rows[] = $row;

                    if (count($rows) >= $limit) {
                        return false;
                    }
                }
            }

            return count($rows) < $limit;
        });

        $source = 'approved_venues';

        if (count($rows) === 0) {
            $fallbackRows = $this->voucherCreationRowsFromProviderLinks($filters, $limit);

            if ($fallbackRows !== []) {
                $rows = $fallbackRows;
                $source = 'provider_link_records';
            }
        }

        $headers = self::VOUCHER_CREATION_HEADERS;
        $summary = [
            'approved_venues' => $venueCount,
            'rows' => count($rows),
            'with_uploaded_links' => collect($rows)->where('has_uploaded_link', 'yes')->count(),
            'missing_uploaded_links' => collect($rows)->where('has_uploaded_link', 'no')->count(),
            'source' => $source,
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'merchant_id' => $merchantId ? (int) $merchantId : null,
                'provider' => $providerFilter ?: null,
                'missing_only' => $missingOnly,
                'limit' => $limit,
            ],
            'summary' => $summary,
            'headers' => $headers,
            'rows' => $rows,
            'csv' => $this->csvFromRows($headers, $rows),
            'tsv' => $this->tsvFromRows($headers, $rows),
            'sheet_tab_name' => 'CAS Voucher Creation',
            'suggested_filename' => 'cas-approved-venue-voucher-creation-' . now()->format('Ymd-His') . '.csv',
            'instructions' => [
                'Each row is an approved venue voucher request for Uber Business / Uber Eats.',
                'Use venue_code / uber_business_reference as the reference code in Uber Business.',
                'Create the voucher using voucher_amount, provider, offer_type, trip type, postcode, date/time, and issue cap.',
                'After Uber exports the final voucher links, upload that CSV/XLSX back into CAS with the same venue_code/reference column so links attach to the correct venue.',
            ],
        ];
    }


    protected function voucherCreationRowsForVenue(Venue $venue): array
    {
        $venue->loadMissing(['merchant', 'providerVoucherLinks']);

        $offerType = $this->venueOfferType($venue);
        $rideTripType = $this->normaliseRideTripType($venue->ride_trip_type) ?: 'to_venue';

        $configs = match ($offerType) {
            'food' => [
                ['provider' => 'ubereats', 'offer_type' => 'food', 'ride_trip_type' => null],
            ],
            'dual_choice' => [
                ['provider' => 'uber', 'offer_type' => 'ride', 'ride_trip_type' => $rideTripType],
                ['provider' => 'ubereats', 'offer_type' => 'food', 'ride_trip_type' => null],
            ],
            default => [
                ['provider' => 'uber', 'offer_type' => 'ride', 'ride_trip_type' => $rideTripType],
            ],
        };

        return collect($configs)
            ->map(fn (array $config) => $this->voucherCreationRow($venue, $config))
            ->values()
            ->all();
    }

    protected function voucherCreationRowsFromProviderLinks(array $filters, int $limit): array
    {
        $query = ProviderVoucherLink::query()
            ->with(['merchant', 'venue'])
            ->withCount('vouchers')
            ->where(function ($query) {
                $query->whereNotNull('venue_id')
                    ->orWhereNotNull('venue_code_reference');
            })
            ->latest();

        if ($merchantId = Arr::get($filters, 'merchant_id')) {
            $query->where('merchant_id', (int) $merchantId);
        }

        if ($provider = Arr::get($filters, 'provider')) {
            $query->where('provider', $provider);
        }

        $missingOnly = filter_var(Arr::get($filters, 'missing_only', false), FILTER_VALIDATE_BOOLEAN);
        if ($missingOnly) {
            return [];
        }

        return $query
            ->take($limit)
            ->get()
            ->map(fn (ProviderVoucherLink $link) => $this->voucherCreationRowFromProviderLink($link))
            ->filter(fn (array $row) => trim((string) ($row['venue_code'] ?? '')) !== '')
            ->values()
            ->all();
    }

    protected function voucherCreationRow(Venue $venue, array $config): array
    {
        $matchingLinks = collect($venue->providerVoucherLinks ?? [])
            ->filter(fn (ProviderVoucherLink $link) => $this->providerLinkMatchesCreationConfig($link, $config))
            ->values();

        $remaining = $this->remainingIssueCount($matchingLinks, $venue->daily_voucher_cap);
        $voucherAmount = $venue->offer_value !== null ? (float) $venue->offer_value : 0;
        $minimumOrder = $config['offer_type'] === 'food' && $venue->minimum_order !== null ? (float) $venue->minimum_order : null;
        $venueCode = $this->normaliseVenueCode($venue->venue_code) ?: strtoupper(trim((string) $venue->venue_code));

        return [
            'venue_code' => $venueCode,
            'uber_business_reference' => $venueCode,
            'merchant_id' => $venue->merchant_id,
            'merchant_name' => $venue->merchant?->business_name,
            'venue_id' => $venue->id,
            'venue_name' => $venue->name,
            'provider' => $config['provider'],
            'offer_type' => $config['offer_type'],
            'ride_trip_type' => $config['ride_trip_type'],
            'voucher_amount' => number_format($voucherAmount, 2, '.', ''),
            'minimum_order' => $minimumOrder !== null ? number_format($minimumOrder, 2, '.', '') : '',
            'location_postcode' => $venue->postcode,
            'address' => $venue->address,
            'city' => $venue->city,
            'start_time' => $this->timeString($venue->offer_start_time),
            'end_time' => $this->timeString($venue->offer_end_time),
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
            'max_issue_count' => $venue->daily_voucher_cap ?: '',
            'has_uploaded_link' => $matchingLinks->isNotEmpty() ? 'yes' : 'no',
            'uploaded_link_count' => $matchingLinks->count(),
            'remaining_issue_count' => $remaining,
            'admin_note' => 'Use venue_code / uber_business_reference as the Uber Business reference when creating this voucher.',
        ];
    }

    protected function voucherCreationRowFromProviderLink(ProviderVoucherLink $link): array
    {
        $payload = $this->payload($link);
        $venueCode = $payload['venue_code_reference'] ?: ($payload['venue']['venue_code'] ?? null);
        $venue = $link->venue;

        return [
            'venue_code' => $venueCode,
            'uber_business_reference' => $venueCode,
            'merchant_id' => $link->merchant_id,
            'merchant_name' => $link->merchant?->business_name,
            'venue_id' => $link->venue_id,
            'venue_name' => $venue?->name ?: ($payload['venue']['name'] ?? null),
            'provider' => $link->provider,
            'offer_type' => $link->offer_type,
            'ride_trip_type' => $link->ride_trip_type,
            'voucher_amount' => $payload['voucher_amount'],
            'minimum_order' => $payload['minimum_order'],
            'location_postcode' => $link->location_postcode ?: $venue?->postcode,
            'address' => $venue?->address,
            'city' => $venue?->city,
            'start_time' => $payload['start_time'],
            'end_time' => $payload['end_time'],
            'valid_from' => $link->valid_from ? $link->valid_from->toDateString() : now()->toDateString(),
            'valid_until' => $link->valid_until ? $link->valid_until->toDateString() : now()->addDays(7)->toDateString(),
            'max_issue_count' => $link->max_issue_count ?: '',
            'has_uploaded_link' => $link->is_active ? 'yes' : 'no',
            'uploaded_link_count' => 1,
            'remaining_issue_count' => $link->max_issue_count !== null ? max((int) $link->max_issue_count - (int) ($link->vouchers_count ?? 0), 0) : '',
            'admin_note' => 'Existing provider-link record. Keep this 6-character reference in Uber Business so CAS can match future uploads.',
        ];
    }

    protected function venueOfferType(Venue $venue): string
    {
        $offerType = trim((string) $venue->offer_type);

        if ($offerType !== '') {
            return $this->normaliseOfferType($offerType);
        }

        $category = strtolower(trim((string) $venue->category));
        $businessType = strtolower(trim((string) $venue->merchant?->business_type));
        $foodKeywords = ['food', 'restaurant', 'takeaway', 'delivery', 'cafe', 'coffee', 'pizza', 'burger'];

        foreach ($foodKeywords as $keyword) {
            if (str_contains($category, $keyword) || str_contains($businessType, $keyword)) {
                return 'food';
            }
        }

        return 'ride';
    }

    protected function providerLinkMatchesCreationConfig(ProviderVoucherLink $link, array $config): bool
    {
        if ($this->normaliseProvider((string) $link->provider) !== $config['provider']) {
            return false;
        }

        $linkOfferType = $this->normaliseOfferType((string) $link->offer_type);
        $expectedOfferType = $config['offer_type'];

        if ($expectedOfferType === 'food' && ! in_array($linkOfferType, ['food', 'dual_choice'], true)) {
            return false;
        }

        if ($expectedOfferType === 'ride' && ! in_array($linkOfferType, ['ride', 'dual_choice'], true)) {
            return false;
        }

        if ($expectedOfferType === 'ride' && $config['ride_trip_type']) {
            $linkTripType = $this->normaliseRideTripType($link->ride_trip_type);

            if ($linkTripType !== null && $linkTripType !== $config['ride_trip_type']) {
                return false;
            }
        }

        return (bool) $link->is_active;
    }

    protected function remainingIssueCount($links, mixed $fallbackCap = null): string|int
    {
        $remaining = collect($links)
            ->map(function (ProviderVoucherLink $link) {
                if ($link->max_issue_count === null) {
                    return null;
                }

                return max((int) $link->max_issue_count - (int) ($link->vouchers_count ?? 0), 0);
            })
            ->filter(fn ($value) => $value !== null)
            ->sum();

        if ($remaining > 0) {
            return $remaining;
        }

        return $fallbackCap ?: '';
    }

    public function batchSummary(int $limit = 8, array $filters = []): array
    {
        $baseQuery = $this->filteredQuery(Arr::except($filters, ['limit']));

        return $baseQuery
            ->selectRaw("COALESCE(import_batch_code, 'manual') as import_batch_code")
            ->selectRaw("COALESCE(source_label, 'manual-entry') as source_label")
            ->selectRaw('COUNT(*) as record_count')
            ->selectRaw('MAX(created_at) as latest_created_at')
            ->groupBy('import_batch_code', 'source_label')
            ->orderByDesc('latest_created_at')
            ->take(max(1, min($limit, 20)))
            ->get()
            ->map(function ($row) {
                return [
                    'import_batch_code' => $row->import_batch_code,
                    'source_label' => $row->source_label,
                    'record_count' => (int) $row->record_count,
                    'latest_created_at' => optional(Carbon::parse($row->latest_created_at))->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    protected function filteredQuery(array $filters = [])
    {
        $query = ProviderVoucherLink::query();

        if ($merchantId = Arr::get($filters, 'merchant_id')) {
            $query->where('merchant_id', $merchantId);
        }

        if ($provider = Arr::get($filters, 'provider')) {
            $query->where('provider', $provider);
        }

        if (($sourceLabel = Arr::get($filters, 'source_label')) !== null && trim((string) $sourceLabel) !== '') {
            $query->where('source_label', trim((string) $sourceLabel));
        }

        if (($batchCode = Arr::get($filters, 'import_batch_code')) !== null && trim((string) $batchCode) !== '') {
            $query->where('import_batch_code', trim((string) $batchCode));
        }

        if (filter_var(Arr::get($filters, 'active_only', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('is_active', true);
        }

        return $query;
    }

    protected function exportRow(ProviderVoucherLink $link): array
    {
        $payload = $this->payload($link);

        return [
            'venue_code' => $payload['venue_code_reference'] ?: ($payload['venue']['venue_code'] ?? null),
            'provider' => $payload['provider'],
            'link_url' => $payload['link_url'],
            'offer_type' => $payload['offer_type'],
            'ride_trip_type' => $payload['ride_trip_type'],
            'voucher_amount' => $payload['voucher_amount'],
            'minimum_order' => $payload['minimum_order'],
            'location_postcode' => $payload['location_postcode'] ?: ($payload['venue']['postcode'] ?? null),
            'start_time' => $payload['start_time'],
            'end_time' => $payload['end_time'],
            'valid_from' => $payload['valid_from'] ? Carbon::parse($payload['valid_from'])->toDateString() : null,
            'valid_until' => $payload['valid_until'] ? Carbon::parse($payload['valid_until'])->toDateString() : null,
            'is_active' => $payload['is_active'] ? 'true' : 'false',
            'circulation_mode' => $payload['circulation_mode'],
            'max_issue_count' => $payload['max_issue_count'],
            'source_label' => $payload['source_label'] ?: ($payload['import_batch_code'] ?: 'manual-entry'),
            'notes' => $payload['notes'],
        ];
    }

    protected function csvFromRows(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return '';
        }

        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }

            fputcsv($stream, $line);
        }

        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $csv;
    }

    protected function tsvFromRows(array $headers, array $rows): string
    {
        $lines = [implode("\t", $headers)];

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = str_replace(["\t", "\r", "\n"], ' ', (string) ($row[$header] ?? ''));
            }

            $lines[] = implode("\t", $line);
        }

        return implode("\n", $lines);
    }

    public function matchActiveLink(Venue $venue, string $journeyType): ?ProviderVoucherLink
    {
        $provider = $this->providerForJourney($journeyType);
        $requiredOfferTypes = $journeyType === 'food'
            ? ['food', 'dual_choice']
            : ['ride', 'dual_choice'];

        $now = now();
        $currentTime = $now->format('H:i:s');
        $venueCode = $this->normaliseVenueCode($venue->venue_code);

        return ProviderVoucherLink::query()
            ->where('merchant_id', $venue->merchant_id)
            ->where(function ($query) use ($venue, $venueCode) {
                $query->where('venue_id', $venue->id);

                if ($venueCode) {
                    $query->orWhereRaw('UPPER(venue_code_reference) = ?', [$venueCode]);
                }

                $query->orWhere(function ($generic) {
                    $generic->whereNull('venue_id')->whereNull('venue_code_reference');
                });
            })
            ->where('is_active', true)
            ->whereIn('offer_type', $requiredOfferTypes)
            ->where('provider', $provider)
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->where(function ($query) use ($currentTime) {
                $query->whereNull('start_time')->orWhere('start_time', '<=', $currentTime);
            })
            ->where(function ($query) use ($currentTime) {
                $query->whereNull('end_time')->orWhere('end_time', '>=', $currentTime);
            })
            ->withCount('vouchers')
            ->get()
            ->filter(function (ProviderVoucherLink $link) {
                return $link->max_issue_count === null || $link->vouchers_count < $link->max_issue_count;
            })
            ->sort(function (ProviderVoucherLink $left, ProviderVoucherLink $right) use ($venue) {
                $leftVenuePriority = $this->providerLinkVenuePriority($left, $venue);
                $rightVenuePriority = $this->providerLinkVenuePriority($right, $venue);

                if ($leftVenuePriority !== $rightVenuePriority) {
                    return $leftVenuePriority <=> $rightVenuePriority;
                }

                $leftValidFrom = optional($left->valid_from)->timestamp ?? 0;
                $rightValidFrom = optional($right->valid_from)->timestamp ?? 0;

                if ($leftValidFrom !== $rightValidFrom) {
                    return $rightValidFrom <=> $leftValidFrom;
                }

                return $right->id <=> $left->id;
            })
            ->first();
    }

    protected function providerLinkVenuePriority(ProviderVoucherLink $link, Venue $venue): int
    {
        if ((int) $link->venue_id === (int) $venue->id) {
            return 0;
        }

        $venueCode = $this->normaliseVenueCode($venue->venue_code);
        $linkCode = $this->normaliseVenueCode($link->venue_code_reference);

        if ($venueCode && $linkCode && $venueCode === $linkCode) {
            return 1;
        }

        return 2;
    }

    public function payload(ProviderVoucherLink $link): array
    {
        $issuedCount = isset($link->vouchers_count) ? (int) $link->vouchers_count : $link->vouchers()->count();
        $remainingCount = $link->max_issue_count !== null ? max((int) $link->max_issue_count - $issuedCount, 0) : null;

        return [
            'id' => $link->id,
            'provider' => $link->provider,
            'link_url' => $link->link_url,
            'offer_type' => $link->offer_type,
            'ride_trip_type' => $link->ride_trip_type,
            'voucher_amount' => $link->voucher_amount !== null ? number_format((float) $link->voucher_amount, 2, '.', '') : null,
            'minimum_order' => $link->minimum_order !== null ? number_format((float) $link->minimum_order, 2, '.', '') : null,
            'location_postcode' => $link->location_postcode,
            'start_time' => $this->timeString($link->start_time),
            'end_time' => $this->timeString($link->end_time),
            'valid_from' => optional($link->valid_from)->toIso8601String(),
            'valid_until' => optional($link->valid_until)->toIso8601String(),
            'is_active' => (bool) $link->is_active,
            'circulation_mode' => $link->circulation_mode ?: 'shared_sequence',
            'max_issue_count' => $link->max_issue_count,
            'issued_count' => $issuedCount,
            'remaining_issue_count' => $remainingCount,
            'source' => $link->source,
            'import_batch_code' => $link->import_batch_code,
            'source_label' => $link->source_label,
            'venue_code_reference' => $link->venue_code_reference,
            'notes' => $link->notes,
            'merchant' => $link->merchant ? [
                'id' => $link->merchant->id,
                'business_name' => $link->merchant->business_name,
            ] : null,
            'venue' => $link->venue ? [
                'id' => $link->venue->id,
                'name' => $link->venue->name,
                'postcode' => $link->venue->postcode,
                'venue_code' => $link->venue->venue_code,
            ] : null,
            'created_by' => $link->creator ? [
                'id' => $link->creator->id,
                'name' => $link->creator->name,
                'email' => $link->creator->email,
            ] : null,
            'created_at' => optional($link->created_at)->toIso8601String(),
        ];
    }

    protected function readImportRows(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return $extension === 'xlsx'
            ? $this->readXlsx($file)
            : $this->readCsv($file);
    }

    protected function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['Unable to read the uploaded CSV file.'],
            ]);
        }

        $header = fgetcsv($handle) ?: [];
        $header = array_map(fn ($value) => trim((string) $value), $header);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null] || $line === false) {
                continue;
            }

            $row = [];
            foreach ($header as $index => $key) {
                $row[$key] = isset($line[$index]) ? trim((string) $line[$index]) : null;
            }

            if ($this->rowHasContent($row)) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    protected function readXlsx(UploadedFile $file): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages([
                'file' => ['XLSX upload needs the PHP zip extension. Upload CSV instead if zip is not enabled.'],
            ]);
        }

        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages([
                'file' => ['Unable to open the uploaded XLSX file.'],
            ]);
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $worksheetPath = $this->firstWorksheetPath($zip);
        $worksheetXml = $zip->getFromName($worksheetPath);
        $zip->close();

        if (! $worksheetXml) {
            throw ValidationException::withMessages([
                'file' => ['The XLSX file does not contain a readable worksheet.'],
            ]);
        }

        $worksheet = simplexml_load_string($worksheetXml);
        if (! $worksheet instanceof SimpleXMLElement) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded XLSX worksheet could not be parsed.'],
            ]);
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $worksheet->registerXPathNamespace('sheet', $namespace);

        $rows = [];

        foreach ($worksheet->xpath('//sheet:sheetData/sheet:row') ?: [] as $rowNode) {
            if ($rowNode instanceof SimpleXMLElement) {
                $rowNode->registerXPathNamespace('sheet', $namespace);
            }

            $cells = [];

            foreach ($rowNode->xpath('sheet:c') ?: [] as $cell) {
                if ($cell instanceof SimpleXMLElement) {
                    $cell->registerXPathNamespace('sheet', $namespace);
                }

                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = $this->columnIndexFromReference($reference);
                $cells[$columnIndex] = $this->xlsxCellValue($cell, $sharedStrings);
            }

            if ($cells !== []) {
                ksort($cells);
                $rows[] = array_values($cells);
            }
        }

        if ($rows === []) {
            return [];
        }

        $header = array_map(fn ($value) => trim((string) $value), array_shift($rows));
        $mappedRows = [];

        foreach ($rows as $line) {
            $row = [];
            foreach ($header as $index => $key) {
                $row[$key] = isset($line[$index]) ? trim((string) $line[$index]) : null;
            }

            if ($this->rowHasContent($row)) {
                $mappedRows[] = $row;
            }
        }

        return $mappedRows;
    }

    protected function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! $xml) {
            return [];
        }

        $sharedStrings = simplexml_load_string($xml);
        if (! $sharedStrings instanceof SimpleXMLElement) {
            return [];
        }

        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sharedStrings->registerXPathNamespace('sheet', $namespace);

        $values = [];

        foreach ($sharedStrings->xpath('//sheet:si') ?: [] as $item) {
            if ($item instanceof SimpleXMLElement) {
                $item->registerXPathNamespace('sheet', $namespace);
            }

            $textParts = $item->xpath('.//sheet:t') ?: [];
            $values[] = trim(collect($textParts)->map(fn ($node) => (string) $node)->implode(''));
        }

        return $values;
    }

    protected function firstWorksheetPath(ZipArchive $zip): string
    {
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! $relsXml) {
            return 'xl/worksheets/sheet1.xml';
        }

        $rels = simplexml_load_string($relsXml);
        if (! $rels instanceof SimpleXMLElement) {
            return 'xl/worksheets/sheet1.xml';
        }

        foreach ($rels->Relationship ?? [] as $relationship) {
            $target = (string) ($relationship['Target'] ?? '');
            if (str_contains($target, 'worksheets/')) {
                return str_starts_with($target, 'xl/') ? $target : 'xl/' . ltrim($target, '/');
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    protected function xlsxCellValue(SimpleXMLElement $cell, array $sharedStrings): string
{
    $type = (string) ($cell['t'] ?? '');
    $value = isset($cell->v) ? (string) $cell->v : '';

    if ($type === 's') {
        return (string) ($sharedStrings[(int) $value] ?? '');
    }

    if ($type === 'inlineStr') {
        $cell->registerXPathNamespace('sheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $parts = $cell->xpath('.//sheet:t') ?: [];

        if ($parts) {
            return trim(collect($parts)->map(fn ($node) => (string) $node)->implode(''));
        }
    }

    return trim($value);
}

    protected function columnIndexFromReference(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference));
        $index = 0;

        foreach (str_split($letters) as $char) {
            $index = ($index * 26) + (ord($char) - 64);
        }

        return max($index - 1, 0);
    }

    protected function rowHasContent(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function mapImportRow(array $row): array
    {
        $mapped = [];

        foreach ($row as $header => $value) {
            $canonical = $this->canonicalHeader((string) $header);
            if ($canonical === null) {
                continue;
            }

            $mapped[$canonical] = is_string($value) ? trim($value) : $value;
        }

        $providerWasGuessed = ! array_key_exists('provider', $mapped) || trim((string) $mapped['provider']) === '';
        $offerTypeWasGuessed = ! array_key_exists('offer_type', $mapped) || trim((string) $mapped['offer_type']) === '';

        $mapped['provider'] = $this->guessProvider($mapped);
        $mapped['offer_type'] = $this->guessOfferType($mapped);
        $mapped['ride_trip_type'] = $this->guessRideTripType($mapped);
        $mapped['is_active'] = $this->guessIsActive($mapped);
        $mapped['circulation_mode'] = $this->guessCirculationMode($mapped);
        $mapped['_provider_was_guessed'] = $providerWasGuessed;
        $mapped['_offer_type_was_guessed'] = $offerTypeWasGuessed;

        return $mapped;
    }

    protected function canonicalHeader(string $header): ?string
    {
        $normalised = $this->normaliseHeaderKey($header);
        if ($normalised === '') {
            return null;
        }

        foreach (self::HEADER_ALIASES as $target => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalised === $this->normaliseHeaderKey($alias)) {
                    return $target;
                }
            }
        }

        return in_array($normalised, self::IMPORT_HEADERS, true)
            ? $normalised
            : null;
    }

    protected function normaliseHeaderKey(string $value): string
    {
        return str_replace(' ', '_', trim((string) preg_replace('/[^a-z0-9]+/i', ' ', strtolower($value))));
    }

    protected function guessProvider(array $payload): string
    {
        $explicit = strtolower(trim((string) Arr::get($payload, 'provider', '')));
        $link = strtolower(trim((string) Arr::get($payload, 'link_url', '')));
        $offerType = strtolower(trim((string) Arr::get($payload, 'offer_type', '')));
        $minimumOrder = Arr::get($payload, 'minimum_order');

        if (in_array($explicit, ['uber', 'ubereats'], true)) {
            return $explicit;
        }

        if (str_contains($link, 'ubereats') || $offerType === 'food' || $minimumOrder !== null && $minimumOrder !== '') {
            return 'ubereats';
        }

        return 'uber';
    }

    protected function guessOfferType(array $payload): string
    {
        $explicit = strtolower(trim((string) Arr::get($payload, 'offer_type', '')));
        $provider = strtolower(trim((string) Arr::get($payload, 'provider', '')));
        $minimumOrder = Arr::get($payload, 'minimum_order');

        if (str_contains($explicit, 'dual')) {
            return 'dual_choice';
        }

        if (str_contains($explicit, 'food')) {
            return 'food';
        }

        if (str_contains($explicit, 'ride')) {
            return 'ride';
        }

        if ($provider === 'ubereats' || ($minimumOrder !== null && $minimumOrder !== '')) {
            return 'food';
        }

        return 'ride';
    }

    protected function guessRideTripType(array $payload): ?string
    {
        $value = strtolower(trim((string) Arr::get($payload, 'ride_trip_type', '')));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '2') || str_contains($value, 'two') || str_contains($value, 'to-and-from') || str_contains($value, 'return')) {
            return 'to_and_from';
        }

        if (str_contains($value, '1') || str_contains($value, 'one') || str_contains($value, 'to venue') || str_contains($value, 'to-venue')) {
            return 'to_venue';
        }

        return in_array($value, ['to_venue', 'to_and_from'], true) ? $value : null;
    }

    protected function guessIsActive(array $payload): bool
    {
        $value = Arr::get($payload, 'is_active');

        if (is_bool($value)) {
            return $value;
        }

        $normalised = strtolower(trim((string) $value));
        if ($normalised === '') {
            return true;
        }

        if (in_array($normalised, ['1', 'true', 'yes', 'active', 'available', 'live', 'enabled'], true)) {
            return true;
        }

        if (in_array($normalised, ['0', 'false', 'no', 'inactive', 'disabled', 'expired', 'unavailable'], true)) {
            return false;
        }

        return true;
    }

    protected function guessCirculationMode(array $payload): string
    {
        $value = strtolower(trim((string) Arr::get($payload, 'circulation_mode', '')));

        if (str_contains($value, 'unique')) {
            return 'unique_individual';
        }

        if (str_contains($value, 'shared') || str_contains($value, 'sequence') || str_contains($value, 'same')) {
            return 'shared_sequence';
        }

        return 'shared_sequence';
    }

    protected function normalisePayload(array $payload, User $admin, string $source): array
    {
        $merchantId = Arr::get($payload, 'merchant_id');
        $venueId = Arr::get($payload, 'venue_id');
        $rawVenueCode = Arr::get($payload, 'venue_code', Arr::get($payload, 'venue_code_reference'));
        $venueCode = $this->normaliseVenueCode($rawVenueCode);
        $linkUrl = trim((string) Arr::get($payload, 'link_url'));
        $venue = null;

        if ($rawVenueCode !== null && trim((string) $rawVenueCode) !== '' && ! $venueCode) {
            throw ValidationException::withMessages([
                'venue_code' => ['Venue code must be exactly 6 alphanumeric characters.'],
            ]);
        }

        if ($venueCode) {
            $venue = Venue::query()
                ->whereRaw('UPPER(venue_code) = ?', [$venueCode])
                ->first();

            if (! $venue) {
                throw ValidationException::withMessages([
                    'venue_code' => ["Venue code {$venueCode} was not found. Export approved venues first and use the exact 6-character reference."],
                ]);
            }

            if ($venue->approval_status !== 'approved' || ! $venue->is_active) {
                throw ValidationException::withMessages([
                    'venue_code' => ["Venue code {$venueCode} belongs to a venue that is not approved yet."],
                ]);
            }

            if ($venueId && (int) $venueId !== (int) $venue->id) {
                throw ValidationException::withMessages([
                    'venue_id' => ["Venue ID does not match venue code {$venueCode}."],
                ]);
            }

            if ($merchantId && (int) $merchantId !== (int) $venue->merchant_id) {
                throw ValidationException::withMessages([
                    'merchant_id' => ["Merchant does not match venue code {$venueCode}."],
                ]);
            }

            $merchantId = $venue->merchant_id;
            $venueId = $venue->id;
        } elseif ($venueId) {
            $venue = Venue::find($venueId);

            if (! $venue) {
                throw ValidationException::withMessages([
                    'venue_id' => ['Selected venue was not found.'],
                ]);
            }

            if ($merchantId && (int) $merchantId !== (int) $venue->merchant_id) {
                throw ValidationException::withMessages([
                    'merchant_id' => ['Merchant does not match the selected venue.'],
                ]);
            }

            $merchantId = $merchantId ?: $venue->merchant_id;
            $venueCode = $this->normaliseVenueCode($venue->venue_code);
        }

        if (! $merchantId) {
            throw ValidationException::withMessages([
                'merchant_id' => ['Merchant is required unless each uploaded row contains a valid venue_code reference.'],
            ]);
        }

        if (! filter_var($linkUrl, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'link_url' => ['Enter a valid Uber / Uber Eats voucher URL.'],
            ]);
        }

        $providerValue = (string) Arr::get($payload, 'provider', 'uber');
        $offerTypeValue = (string) Arr::get($payload, 'offer_type', 'ride');
        $rideTripTypeValue = Arr::get($payload, 'ride_trip_type');
        $voucherAmountValue = Arr::get($payload, 'voucher_amount');
        $minimumOrderValue = Arr::get($payload, 'minimum_order');
        $locationPostcodeValue = Arr::get($payload, 'location_postcode');
        $startTimeValue = Arr::get($payload, 'start_time');
        $endTimeValue = Arr::get($payload, 'end_time');
        $maxIssueCountValue = Arr::get($payload, 'max_issue_count');

        if ($venue) {
            if (Arr::get($payload, '_offer_type_was_guessed', false) && trim((string) $venue->offer_type) !== '') {
                $offerTypeValue = (string) $venue->offer_type;
            }

            if (Arr::get($payload, '_provider_was_guessed', false)) {
                $normalisedOffer = $this->normaliseOfferType($offerTypeValue);
                $providerValue = $normalisedOffer === 'food' ? 'ubereats' : $this->guessProvider($payload);
                if ($providerValue === 'uber' && $normalisedOffer === 'dual_choice' && str_contains(strtolower($linkUrl), 'ubereats')) {
                    $providerValue = 'ubereats';
                }
            }

            if (($rideTripTypeValue === null || trim((string) $rideTripTypeValue) === '') && trim((string) $venue->ride_trip_type) !== '') {
                $rideTripTypeValue = $venue->ride_trip_type;
            }

            if ($voucherAmountValue === null || trim((string) $voucherAmountValue) === '') {
                $voucherAmountValue = $venue->offer_value;
            }

            if (($minimumOrderValue === null || trim((string) $minimumOrderValue) === '') && $venue->minimum_order !== null) {
                $minimumOrderValue = $venue->minimum_order;
            }

            if (($locationPostcodeValue === null || trim((string) $locationPostcodeValue) === '') && trim((string) $venue->postcode) !== '') {
                $locationPostcodeValue = $venue->postcode;
            }

            if (($startTimeValue === null || trim((string) $startTimeValue) === '') && $venue->offer_start_time !== null) {
                $startTimeValue = $venue->offer_start_time;
            }

            if (($endTimeValue === null || trim((string) $endTimeValue) === '') && $venue->offer_end_time !== null) {
                $endTimeValue = $venue->offer_end_time;
            }

            if (($maxIssueCountValue === null || trim((string) $maxIssueCountValue) === '') && $venue->daily_voucher_cap !== null) {
                $maxIssueCountValue = $venue->daily_voucher_cap;
            }
        }

        return [
            'merchant_id' => (int) $merchantId,
            'venue_id' => $venueId ? (int) $venueId : null,
            'venue_code_reference' => $venueCode,
            'created_by_user_id' => $admin->id,
            'provider' => $this->normaliseProvider($providerValue),
            'link_url' => $linkUrl,
            'offer_type' => $this->normaliseOfferType($offerTypeValue),
            'ride_trip_type' => $this->normaliseRideTripType($rideTripTypeValue),
            'voucher_amount' => $this->nullableDecimal($voucherAmountValue),
            'minimum_order' => $this->nullableDecimal($minimumOrderValue),
            'location_postcode' => $this->nullableUpperString($locationPostcodeValue),
            'start_time' => $this->nullableTime($startTimeValue),
            'end_time' => $this->nullableTime($endTimeValue),
            'valid_from' => $this->nullableDate(Arr::get($payload, 'valid_from')),
            'valid_until' => $this->nullableDate(Arr::get($payload, 'valid_until')),
            'is_active' => filter_var(Arr::get($payload, 'is_active', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            'circulation_mode' => $this->normaliseCirculationMode(Arr::get($payload, 'circulation_mode')),
            'max_issue_count' => $this->nullableInteger($maxIssueCountValue),
            'source' => $source,
            'import_batch_code' => Arr::get($payload, 'import_batch_code'),
            'source_label' => Arr::get($payload, 'source_label'),
            'notes' => Arr::get($payload, 'notes'),
            'metadata' => [
                'uploaded_from' => $source,
                'venue_code_reference' => $venueCode,
            ],
        ];
    }

    protected function normaliseVenueCode(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $value));

        return strlen($code) === 6 ? $code : null;
    }

    protected function providerForJourney(string $journeyType): string
    {
        return strtolower(trim($journeyType)) === 'food' ? 'ubereats' : 'uber';
    }

    protected function normaliseProvider(string $value): string
    {
        $value = strtolower(trim($value));
        $normalised = $this->normaliseHeaderKey($value);

        if (in_array($normalised, ['ubereats', 'uber_eats', 'eats', 'uber_eat'], true) || str_contains($value, 'eats')) {
            return 'ubereats';
        }

        return 'uber';
    }

    protected function normaliseOfferType(string $value): string
    {
        $value = strtolower(trim($value));

        if (str_contains($value, 'dual') || str_contains($value, 'both')) {
            return 'dual_choice';
        }

        if (str_contains($value, 'food') || str_contains($value, 'eat') || str_contains($value, 'delivery') || str_contains($value, 'order')) {
            return 'food';
        }

        return 'ride';
    }

    protected function normaliseRideTripType(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = strtolower(trim((string) $value));

        if (str_contains($value, '2') || str_contains($value, 'two') || str_contains($value, 'to-and-from') || str_contains($value, 'return')) {
            return 'to_and_from';
        }

        if (str_contains($value, '1') || str_contains($value, 'one') || str_contains($value, 'to venue') || str_contains($value, 'to-venue')) {
            return 'to_venue';
        }

        return in_array($value, ['to_venue', 'to_and_from'], true) ? $value : null;
    }

    protected function normaliseCirculationMode(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        if (str_contains($value, 'unique')) {
            return 'unique_individual';
        }

        return 'shared_sequence';
    }

    protected function nullableInteger(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalised = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $normalised === '' ? null : (float) $normalised;
    }

    protected function nullableDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->startOfDay()->addDays((int) floor((float) $value));
        }

        return Carbon::parse((string) $value);
    }

    protected function nullableTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 1) {
            $seconds = (int) round((float) $value * 86400);
            return gmdate('H:i:s', $seconds);
        }

        $time = trim((string) $value);

        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time . ':00';
        }

        return preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) ? $time : null;
    }

    protected function nullableUpperString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return strtoupper(trim((string) $value));
    }

    protected function timeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 5);
    }
}
