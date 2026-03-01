<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash Ledger tracks ALL money movements in and out of the system.
     * RULE: Every disbursement and payment must have a ledger entry.
     * This is the single source of truth for cash position.
     */
    public function up()
    {
        Schema::create('cash_ledger', function (Blueprint $table) {
            $table->id();
            
            // What type of transaction is this?
            $table->enum('transaction_type', [
                'disbursement',  // Money OUT (loan given to borrower)
                'payment',       // Money IN (borrower pays back)
                'capital_in',    // Money IN (owner adds capital)
                'capital_out',   // Money OUT (owner withdraws)
                'expense',       // Money OUT (operational costs)
                'other'
            ]);
            
            // Money IN or Money OUT?
            // Positive = Money IN
            // Negative = Money OUT
            $table->decimal('amount', 15, 2);
            
            // Which loan does this relate to? (null for non-loan transactions)
            $table->foreignId('loan_id')->nullable()->constrained();
            
            // Which payment does this relate to? (for payment type transactions)
            $table->foreignId('payment_id')->nullable()->constrained();
            
            // Who performed this transaction?
            $table->foreignUuid('user_id')->constrained('users');
            
            // When did this happen?
            $table->date('transaction_date');
            $table->time('transaction_time')->nullable();
            
            // Description of this transaction
            $table->text('description');
            
            // Reference number (receipt, disbursement voucher, etc.)
            $table->string('reference_number')->nullable();
            
            $table->timestamps();
            
            // Indexes for fast calculations
            $table->index(['transaction_date', 'transaction_type']);
            $table->index('transaction_type');
            $table->index('loan_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_ledger');
    }
};
