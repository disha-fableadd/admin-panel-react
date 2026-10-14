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
        Schema::table('clients', function (Blueprint $table) {
            $table->string('gst_type')->nullable()->after('location'); // withGst or withoutGst
            $table->decimal('gst_percentage', 5, 2)->default(0)->after('gst_type');
            $table->decimal('gst_amount', 12, 2)->default(0)->after('gst_percentage');
            $table->decimal('total_amount', 12, 2)->default(0)->after('gst_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['gst_type', 'gst_percentage', 'gst_amount', 'total_amount']);
        });
    }
};
