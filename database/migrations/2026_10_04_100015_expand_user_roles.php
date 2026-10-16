<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the unused "moderator" role with scoped manager roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setRoles(['player', 'moderator', 'content_manager', 'competition_manager', 'finance_manager', 'admin', 'super_admin']);

        DB::table('users')->where('role', 'moderator')->update(['role' => 'content_manager']);

        $this->setRoles(['player', 'content_manager', 'competition_manager', 'finance_manager', 'admin', 'super_admin']);
    }

    public function down(): void
    {
        $this->setRoles(['player', 'moderator', 'content_manager', 'competition_manager', 'finance_manager', 'admin', 'super_admin']);

        DB::table('users')
            ->whereIn('role', ['content_manager', 'competition_manager', 'finance_manager'])
            ->update(['role' => 'moderator']);

        $this->setRoles(['player', 'moderator', 'admin', 'super_admin']);
    }

    /**
     * @param  list<string>  $roles
     */
    protected function setRoles(array $roles): void
    {
        Schema::table('users', function (Blueprint $table) use ($roles) {
            $table->enum('role', $roles)->default('player')->change();
        });
    }
};
