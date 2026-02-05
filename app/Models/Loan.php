<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'loan_number', 'borrower_id', 'agent_id', 'market_id', 'approved_by',
        'principal_amount', 'interest_rate', 'interest_amount', 'total_amount',
        'amount_paid', 'balance', 'duration_days', 'disbursement_date', 'due_date',
        'repayment_frequency', 'installment_amount', 'status', 'collection_day',
        'collection_time', 'collection_location', 'purpose', 'notes', 'rejection_reason',
        'approved_at', 'rejected_at', 'disbursed_at', 'completed_at'
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'disbursement_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function market()
    {
        return $this->belongsTo(Market::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }


    public function activities()
    {
        return $this->hasMany(LoanActivity::class);
    }

    public function repaymentSchedules()
    {
        return $this->hasMany(RepaymentSchedule::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function cashLedgerEntries()
    {
        return $this->hasMany(CashLedger::class);
    }

    // Helper methods
    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isApproved()
    {
        return $this->status === 'approved';
    }

    public function isActive()
    {
        return $this->status === 'active';
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isOverdue()
    {
        return $this->status === 'active' && $this->due_date < now();
    }

    public function calculateInterest()
    {
        return ($this->principal_amount * $this->interest_rate) / 100;
    }

    public function calculateTotal()
    {
        return $this->principal_amount + $this->interest_amount;
    }

    public function calculateBalance()
    {
        return $this->total_amount - $this->amount_paid;
    }

    // Generate loan number
    public static function generateLoanNumber()
    {
        $date = now()->format('Ymd');
        $lastLoan = self::whereDate('created_at', today())->latest()->first();
        $sequence = $lastLoan ? intval(substr($lastLoan->loan_number, -4)) + 1 : 1;
        
        return 'BM-' . $date . '-' . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}