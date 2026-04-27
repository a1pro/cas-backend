<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 50)->nullable()->after('email');
                $table->index('role');
            }
        });

        if (Schema::hasTable('user_roles')) {
            $roles = DB::table('user_roles')
                ->select('user_id', 'role')
                ->orderBy('user_id')
                ->orderByDesc('assigned_at')
                ->get();

            foreach ($roles as $role) {
                DB::table('users')
                    ->where('id', $role->user_id)
                    ->whereNull('role')
                    ->update(['role' => $role->role]);
            }
        }

        DB::table('users')->whereNull('role')->update(['role' => 'user']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role')) {
                $table->dropIndex(['role']);
                $table->dropColumn('role');
            }
        });
    }
};
