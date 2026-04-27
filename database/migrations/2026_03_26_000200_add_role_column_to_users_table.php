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
                $table->string('role', 50)->nullable()->after('longitude');
            }
        });

        $rows = DB::table('user_roles')->select('user_id', 'role')->get();
        foreach ($rows as $row) {
            DB::table('users')->where('id', $row->user_id)->update(['role' => $row->role]);
        }

        DB::table('users')->whereNull('role')->update(['role' => 'user']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }
};
