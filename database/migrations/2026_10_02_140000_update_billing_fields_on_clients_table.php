<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First drop the old decimal column
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('renewal_amount');
        });

        // Then add the new json columns
        Schema::table('clients', function (Blueprint $table) {
            $table->json('amount')->nullable()->after('billing_title');
            $table->json('renewal_amount')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['amount', 'renewal_amount']);
            $table->decimal('renewal_amount', 10, 2)->default(0)->after('billing_title');
        });
    }
};
