<?php

namespace App\Services\Admin;

use App\Models\Coupon;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CouponManagementService
{
    public function create(array $payload, User $admin): Coupon
    {
        return Coupon::create($this->normalisePayload($payload, $admin, 'manual'));
    }

    public function importFromCsv(UploadedFile $file, User $admin, array $defaults = []): array
    {
        $rows = $this->readCsv($file);
        $created = [];

        DB::transaction(function () use ($rows, $admin, $file, $defaults, &$created) {
            foreach ($rows as $index => $row) {
                $payload = array_merge($defaults, array_filter($row, fn ($value) => $value !== null && $value !== ''));

                if (! Arr::get($payload, 'code')) {
                    throw ValidationException::withMessages([
                        'file' => ["Row " . ($index + 2) . " is missing a coupon code."],
                    ]);
                }

                $created[] = Coupon::create(
                    $this->normalisePayload($payload, $admin, 'csv_upload', $file->getClientOriginalName())
                );
            }
        });

        return $created;
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
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    protected function normalisePayload(array $payload, User $admin, string $source, ?string $uploadedFileName = null): array
    {
        $merchantId = Arr::get($payload, 'merchant_id') ?: null;
        $venueId = Arr::get($payload, 'venue_id') ?: null;

        if ($merchantId && ! $venueId) {
            $venueId = optional(Merchant::with('venues')->find($merchantId)?->venues?->first())->id;
        }

        return [
            'merchant_id' => $merchantId,
            'venue_id' => $venueId,
            'created_by' => $admin->id,
            'title' => Arr::get($payload, 'title', 'Imported coupon'),
            'journey_type' => $this->normaliseJourneyType(Arr::get($payload, 'journey_type', 'order_food')),
            'provider' => $this->normaliseProvider(Arr::get($payload, 'provider', 'manual')),
            'code' => trim((string) Arr::get($payload, 'code')),
            'discount_amount' => (float) Arr::get($payload, 'discount_amount', 0),
            'minimum_order' => $this->nullableDecimal(Arr::get($payload, 'minimum_order')),
            'is_new_customer_only' => filter_var(Arr::get($payload, 'is_new_customer_only', false), FILTER_VALIDATE_BOOLEAN),
            'starts_at' => $this->nullableDate(Arr::get($payload, 'starts_at')),
            'expires_at' => $this->nullableDate(Arr::get($payload, 'expires_at')),
            'status' => $this->normaliseStatus(Arr::get($payload, 'status', 'draft')),
            'source' => $source,
            'uploaded_file_name' => $uploadedFileName,
            'notes' => Arr::get($payload, 'notes'),
        ];
    }

    protected function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    protected function nullableDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    protected function normaliseJourneyType(string $value): string
    {
        return in_array($value, ['going_out', 'order_food'], true) ? $value : 'order_food';
    }

    protected function normaliseProvider(string $value): string
    {
        return in_array($value, ['uber', 'ubereats', 'manual'], true) ? $value : 'manual';
    }

    protected function normaliseStatus(string $value): string
    {
        return in_array($value, ['draft', 'live', 'expired', 'archived', 'reserved'], true) ? $value : 'draft';
    }
}
