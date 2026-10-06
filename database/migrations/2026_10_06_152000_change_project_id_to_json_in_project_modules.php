<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_modules', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        DB::statement('ALTER TABLE project_modules MODIFY project_id JSON');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE project_modules MODIFY project_id BIGINT UNSIGNED NULL');
        
        Schema::table('project_modules', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
        });
    }
};
