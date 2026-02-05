<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments represent ACTUAL money collected from borrowers.
     * This is the source of truth for what has been received.
     */
    public function up()
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            
            // Which loan is this payment for?
            $table->foreignId('loan_id')->constrained()->onDelete('cascade');
            
            // Who collected this payment?
            $table->foreignId('collected_by')->constrained('users');
            
            // When was the money actually received?
            $table->date('payment_date');
            $table->time('payment_time')->nullable();
            
            // How much was collected? (This is the ONLY amount that matters)
            $table->decimal('amount', 15, 2);
            
            // Payment method for tracking
            $table->enum('payment_method', ['cash', 'bank_transfer', 'mobile_money', 'other'])->default('cash');
            
            // Receipt/reference number for this transaction
            $table->string('receipt_number')->unique()->nullable();
            
            // Optional: Which schedule(s) does this payment cover?
            // This can be NULL - payment is valid regardless of schedule
            $table->foreignId('repayment_schedule_id')->nullable()->constrained();
            
            // Where was this payment collected?
            $table->string('collection_location')->nullable();
            
            // Any notes about this payment
            $table->text('notes')->nullable();
            
            // Was this payment verified?
            $table->boolean('is_verified')->default(true);
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            
            $table->timestamps();
            
            // Indexes for reporting
            $table->index(['loan_id', 'payment_date']);
            $table->index(['payment_date', 'collected_by']);
            $table->index('collected_by');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payments');
    }
};
