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
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('gateway_environment')->default('Live Production Mode');
                $table->string('settlement_currency')->default('INR (₹ - Indian Rupee)');
                $table->string('auto_capture')->default('Immediate Capture (Recommended)');
                $table->string('key_id')->nullable();
                $table->text('key_secret')->nullable();
                $table->text('webhook_secret')->nullable();
                $table->string('status')->default('Active');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
