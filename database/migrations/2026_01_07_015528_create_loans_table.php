<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_number')->unique(); // LN-20240101-0001
            $table->foreignId('borrower_id')->constrained()->onDelete('cascade');
            $table->foreignId('agent_id')->constrained('users'); // Agent collecting
            $table->foreignId('market_id')->constrained();
            $table->foreignId('approved_by')->nullable()->constrained('users'); // Supervisor
            
            // Loan amounts
            $table->decimal('principal_amount', 15, 2); // Amount borrowed
            $table->decimal('interest_rate', 5, 2); // Percentage
            $table->decimal('interest_amount', 15, 2); // Calculated interest
            $table->decimal('total_amount', 15, 2); // Principal + Interest
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2); // Remaining balance
            
            // Loan terms
            $table->integer('duration_days'); // Loan duration in days
            $table->date('disbursement_date')->nullable(); // When money was given
            $table->date('due_date')->nullable(); // When full payment is due
            $table->enum('repayment_frequency', ['daily', 'weekly', 'bi-weekly', 'monthly'])->default('daily');
            $table->decimal('installment_amount', 15, 2)->nullable(); // Amount per installment
            
            // Loan status
            $table->enum('status', [
                'pending',      // Awaiting approval
                'approved',     // Approved by supervisor
                'rejected',     // Rejected by supervisor
                'disbursed',    // Money given to borrower
                'active',       // Currently being repaid
                'completed',    // Fully paid
                'defaulted',    // Payment overdue
                'written_off'   // Bad debt
            ])->default('pending');
            
            // Collection details
            $table->string('collection_day')->nullable(); // For weekly/monthly (e.g., Monday, 1st of month)
            $table->time('collection_time')->nullable();
            $table->text('collection_location')->nullable();
            
            // Additional info
            $table->text('purpose')->nullable(); // What loan is for
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // Timestamps for status changes
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('status');
            $table->index('due_date');
            $table->index(['agent_id', 'status']);
            $table->index(['market_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('loans');
    }
};