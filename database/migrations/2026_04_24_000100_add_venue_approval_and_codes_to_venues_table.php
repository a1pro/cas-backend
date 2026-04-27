<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            if (! Schema::hasColumn('venues', 'approval_status')) {
                $table->string('approval_status', 20)->default('pending')->after('is_active');
            }

            if (! Schema::hasColumn('venues', 'venue_code')) {
                $table->string('venue_code', 6)->nullable()->unique()->after('approval_status');
            }

            if (! Schema::hasColumn('venues', 'submitted_for_approval_at')) {
                $table->timestamp('submitted_for_approval_at')->nullable()->after('venue_code');
            }

            if (! Schema::hasColumn('venues', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('submitted_for_approval_at');
            }

            if (! Schema::hasColumn('venues', 'approved_by_user_id')) {
                $table->foreignId('approved_by_user_id')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('venues', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_by_user_id');
            }

            if (! Schema::hasColumn('venues', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }
        });

        DB::table('venues')
            ->orderBy('id')
            ->get()
            ->each(function ($venue): void {
                $isActive = (bool) ($venue->is_active ?? false);
                $status = $venue->approval_status ?? null;

                $updates = [
                    'approval_status' => $status ?: ($isActive ? 'approved' : 'pending'),
                    'submitted_for_approval_at' => $venue->submitted_for_approval_at ?? $venue->created_at ?? now(),
                ];

                if ($isActive && empty($venue->venue_code)) {
                    $updates['venue_code'] = $this->generateUniqueVenueCode();
                    $updates['approved_at'] = $venue->approved_at ?? $venue->updated_at ?? now();
                }

                DB::table('venues')->where('id', $venue->id)->update($updates);
            });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            foreach (['rejection_reason', 'rejected_at'] as $column) {
                if (Schema::hasColumn('venues', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('venues', 'approved_by_user_id')) {
                $table->dropConstrainedForeignId('approved_by_user_id');
            }

            foreach (['approved_at', 'submitted_for_approval_at', 'venue_code', 'approval_status'] as $column) {
                if (Schema::hasColumn('venues', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function generateUniqueVenueCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (DB::table('venues')->where('venue_code', $code)->exists());

        return $code;
    }
};
