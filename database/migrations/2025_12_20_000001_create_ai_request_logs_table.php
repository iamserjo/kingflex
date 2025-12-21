<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_request_logs', function (Blueprint $table): void {
            $table->id();

            $table->uuid('trace_id')->index();

            $table->string('provider', 50)->index();
            $table->string('model', 255)->nullable()->index();

            $table->string('http_method', 10)->nullable();
            $table->string('base_url', 2048)->nullable();
            $table->string('path', 512)->nullable();

            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->text('response_body')->nullable();
            $table->jsonb('usage')->nullable();
            $table->jsonb('error')->nullable();

            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_request_logs');
    }
};


