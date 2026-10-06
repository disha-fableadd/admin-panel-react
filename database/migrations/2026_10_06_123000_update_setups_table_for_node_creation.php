<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setups', function (Blueprint $table) {
            // Drop old columns if needed
            if (Schema::hasColumn('setups', 'db_credential')) {
                $table->dropColumn('db_credential');
            }

            // Add new columns
            $table->string('client_name')->nullable();
            $table->string('product')->nullable();
            $table->string('database_name')->nullable();
            $table->string('db_host')->nullable();
            $table->string('db_username')->nullable();
            $table->string('db_password')->nullable();
            $table->integer('db_port')->default(5432);
            $table->boolean('ssl_enabled')->default(true);
            $table->string('status')->default('Active');
            $table->string('version')->default('v1.0.0')->nullable();
            $table->string('plan_name')->nullable();
            $table->string('billing_cycle')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->decimal('renewal_amount', 10, 2)->nullable();
            $table->json('assigned_modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('setups', function (Blueprint $table) {
            $table->dropColumn([
                'client_name', 'product', 'database_name', 'db_host', 
                'db_username', 'db_password', 'db_port', 'ssl_enabled', 
                'status', 'version', 'plan_name', 'billing_cycle', 
                'amount', 'renewal_amount', 'assigned_modules'
            ]);
            $table->json('db_credential')->nullable();
        });
    }
};
