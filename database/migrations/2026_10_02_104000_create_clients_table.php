<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->string('client_name');
                $table->string('brand_name')->nullable();
                $table->string('work_email')->nullable();
                $table->string('mobile')->nullable();
                
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->foreignId('membership_id')->constrained('memberships')->onDelete('cascade');
                
                $table->string('status')->default('Active');
                
                // Billing Info
                $table->boolean('is_custom_billing')->default(false);
                $table->string('billing_title')->nullable();
                $table->decimal('renewal_amount', 10, 2)->default(0);
                
                // Other fields
                $table->date('start_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('location')->nullable();
                $table->string('domain')->nullable();
                $table->json('db_credential')->nullable();
                
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
