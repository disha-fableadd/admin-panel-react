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
        Schema::table('project_modules', function (Blueprint $table) {
            // Adding client_id as JSON so it can store multiple client IDs, nullable
            $table->json('client_id')->nullable()->after('project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            $table->dropColumn('client_id');
        });
    }
};
