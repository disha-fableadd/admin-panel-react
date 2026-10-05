<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');

            // Renewal Status: Upcoming, Completed, Overdue, Cancelled
            $table->string('renewal_status')->default('Upcoming');

            // Amount for this renewal (snapshot from client renewal_amount)
            $table->decimal('amount', 10, 2)->default(0);

            // Dates
            $table->date('previous_end_date')->nullable();
            $table->date('renewal_date')->nullable();      // When renewal was/will be processed
            $table->date('new_end_date')->nullable();      // New expiry after renewal

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewals');
    }
};
