<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            // Drop foreign key first so we can modify the column
            $table->dropForeign(['project_modules_id']);
            
            // Change it to JSON
            // For MySQL/Postgres we can use ->change(), but raw statement is sometimes safer across DB engines
        });

        // Using raw statement to change column type to JSON
        DB::statement('ALTER TABLE memberships MODIFY project_modules_id JSON');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE memberships MODIFY project_modules_id BIGINT UNSIGNED');
        
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreign('project_modules_id')->references('id')->on('project_modules')->onDelete('cascade');
        });
    }
};
