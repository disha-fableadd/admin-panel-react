<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['billing_id']);
            $table->dropColumn('billing_id');
            $table->dropColumn(['amount', 'renewal_charge']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->boolean('is_custom_billing')->default(false)->after('project_modules_id');
            $table->string('billing_title')->nullable()->after('is_custom_billing');
            $table->json('amount')->nullable()->after('billing_title');
            $table->json('renewal_amount')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['is_custom_billing', 'billing_title', 'amount', 'renewal_amount']);
            $table->foreignId('billing_id')->nullable()->constrained('billings')->onDelete('cascade');
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('renewal_charge', 10, 2)->default(0);
        });
    }
};
