<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Developer role was removed: property developers now publish as
     * owners (with an optional registered business name). Existing developer
     * accounts and pending developer applications become owner ones; their
     * listings, inquiries and messages are untouched.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'developer')->update(['role' => 'owner']);

        DB::table('publisher_applications')
            ->where('requested_role', 'developer')
            ->update(['requested_role' => 'owner', 'ownership_type' => DB::raw("COALESCE(ownership_type, 'company')")]);
    }

    public function down(): void
    {
        // One-way: converted accounts cannot be told apart from other owners.
    }
};
