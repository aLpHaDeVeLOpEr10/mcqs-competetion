<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Public alias shown on leaderboards; the real name is never broadcast.
            $table->string('display_name', 50)->nullable()->after('name');
            $table->enum('role', ['player', 'moderator', 'admin', 'super_admin'])->default('player')->after('password');
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active')->after('role');
            $table->softDeletes();

            $table->index('role');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropSoftDeletes();
            $table->dropColumn(['display_name', 'role', 'status']);
        });
    }
};
