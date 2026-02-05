<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repayment schedules represent EXPECTED obligations for a loan.
     * These are created when a loan is disbursed.
     * They do NOT represent actual money collected.
     */
    public function up()
    {
        Schema::create('repayment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->onDelete('cascade');
            
            // When is this payment expected?
            $table->date('due_date');
            
            // How much is expected on this date?
            $table->decimal('expected_amount', 15, 2);
            
            // What installment number is this? (1, 2, 3, etc.)
            $table->integer('installment_number');
            
            // Status helps with queries but is NOT used for calculations
            // Status is derived from: "Are there payments covering this obligation?"
            $table->enum('status', ['pending', 'paid', 'overdue'])->default('pending');
            
            // Notes for this specific installment
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['loan_id', 'due_date']);
            $table->index(['due_date', 'status']);
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('repayment_schedules');
    }
};
