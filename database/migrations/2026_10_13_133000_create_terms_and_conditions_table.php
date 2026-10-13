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
        if (!Schema::hasTable('terms_and_conditions')) {
            Schema::create('terms_and_conditions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('cascade');
                $table->json('title');
                $table->string('status')->default('Active');
                $table->timestamps();

                $table->index(['product_id', 'project_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms_and_conditions');
    }
};
