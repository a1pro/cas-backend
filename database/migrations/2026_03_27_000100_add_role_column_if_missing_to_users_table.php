<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role')->default('user')->after('password');
            });
        }

        if (Schema::hasTable('user_roles') && Schema::hasColumn('users', 'role')) {
            $roleRows = DB::table('user_roles')->select('user_id', 'role')->get();
            foreach ($roleRows as $roleRow) {
                DB::table('users')->where('id', $roleRow->user_id)->update(['role' => $roleRow->role]);
            }
        }
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
