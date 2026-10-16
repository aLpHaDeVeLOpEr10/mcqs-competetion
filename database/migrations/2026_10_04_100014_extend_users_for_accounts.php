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
            // Restricted: may sign in, but may not enter competitions or transact.
            $table->enum('status', ['active', 'restricted', 'suspended', 'closed'])->default('active')->change();
            // Optional explanation shown to the account holder for a non-active status.
            $table->string('status_reason', 255)->nullable()->after('status');
            // New address awaiting confirmation; replaces email only once confirmed.
            $table->string('pending_email', 255)->nullable()->after('email_verified_at');
            $table->dateTime('terms_accepted_at')->nullable()->after('pending_email');

            // Display names identify players publicly, so they must be unique (collation is case-insensitive).
            $table->unique('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['display_name']);
            $table->dropColumn(['status_reason', 'pending_email', 'terms_accepted_at']);
        });

        // Any restricted account becomes suspended so the narrower enum can be restored without data loss errors.
        DB::table('users')->where('status', 'restricted')->update(['status' => 'suspended']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active')->change();
        });
    }
};
