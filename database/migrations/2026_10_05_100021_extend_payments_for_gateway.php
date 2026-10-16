<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Lifecycle: created → pending → processing → succeeded | failed | expired (→ refunded).
        DB::statement("ALTER TABLE payment_transactions MODIFY status ENUM('created','pending','processing','succeeded','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'created'");

        Schema::table('payment_transactions', function (Blueprint $table) {
            // Internal payment reference sent to the provider and shown to the player.
            $table->char('reference', 26)->nullable()->after('id');
            // Provider-reported status text (sanitized), for support.
            $table->string('provider_status', 64)->nullable()->after('status');
            // How to send the player to the provider (redirect URL or form fields); never secrets.
            $table->json('checkout')->nullable()->after('gateway_payload');
            $table->dateTime('expires_at', 3)->nullable()->after('checkout');
            // When the provider's server-side status confirmed the outcome.
            $table->dateTime('verified_at', 3)->nullable()->after('expires_at');
        });

        foreach (DB::table('payment_transactions')->whereNull('reference')->pluck('id') as $id) {
            DB::table('payment_transactions')->where('id', $id)->update(['reference' => (string) Str::ulid()]);
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->char('reference', 26)->nullable(false)->change();
            $table->unique('reference');
            $table->index(['status', 'expires_at']);
        });

        // Every provider notification as received: kept even when rejected, for investigation.
        Schema::create('payment_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->restrictOnDelete();
            // sha256 of the raw body, to spot replays.
            $table->char('body_hash', 64);
            $table->boolean('signature_valid');
            $table->enum('outcome', ['applied', 'duplicate', 'pending', 'rejected']);
            $table->string('reason', 191)->nullable();
            // Sanitized copy: no signatures, hashes, secrets or card data.
            $table->json('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['gateway', 'created_at']);
            $table->index('body_hash');
        });
        SchemaConstraints::appendOnly('payment_callbacks');
    }

    public function down(): void
    {
        SchemaConstraints::dropAppendOnly('payment_callbacks');
        Schema::dropIfExists('payment_callbacks');

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'provider_status', 'checkout', 'expires_at', 'verified_at']);
        });

        if (DB::table('payment_transactions')->whereIn('status', ['created', 'refunded'])->exists()) {
            throw new RuntimeException('Payments in created/refunded status exist; cannot shrink the status enum.');
        }
        DB::statement("ALTER TABLE payment_transactions MODIFY status ENUM('pending','processing','succeeded','failed','cancelled','expired') NOT NULL DEFAULT 'pending'");
    }
};
