<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('client_project_modules')) {
            Schema::create('client_project_modules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
                $table->foreignId('project_module_id')->constrained('project_modules')->onDelete('cascade');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_modules');
    }
};
