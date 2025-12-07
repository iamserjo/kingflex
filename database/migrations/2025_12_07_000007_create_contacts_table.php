<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable()->comment('Company name');
            $table->string('email')->nullable()->comment('Contact email');
            $table->string('phone')->nullable()->comment('Contact phone');
            $table->text('address')->nullable()->comment('Physical address');
            $table->json('social_links')->nullable()->comment('Social media links');
            $table->timestamps();

            $table->index('page_id');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};

