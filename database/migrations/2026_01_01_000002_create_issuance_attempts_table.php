<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status'); // PENDING, SUCCESS, FAILED, TIMEOUT, NEEDS_RECONCILIATION
            $table->string('scenario')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->string('policy_number')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['policy_request_id', 'attempt_number'], 'uq_policy_attempt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
