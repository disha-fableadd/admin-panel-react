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
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('billing_id')->constrained('billings')->onDelete('cascade');
            $table->foreignId('project_modules_id')->constrained('project_modules')->onDelete('cascade');
            
            $table->string('plan_name');
            $table->string('status')->default('Active');
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('renewal_charge', 10, 2)->default(0);
            $table->integer('max_user')->default(0);
            $table->integer('max_branch')->default(0);
            $table->text('notes')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
