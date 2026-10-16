<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // NULL actor = system (scheduler, queue job).
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            // Dotted event code, e.g. room.status_changed, prize.approved.
            $table->string('action', 100);
            $table->nullableMorphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });

        SchemaConstraints::appendOnly('audit_logs');
    }

    public function down(): void
    {
        SchemaConstraints::dropAppendOnly('audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};
