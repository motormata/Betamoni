<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class RepaymentSchedule extends Model
{
    protected $fillable = [
        'loan_id',
        'due_date',
        'expected_amount',
        'installment_number',
        'status',
        'notes'
    ];

    protected $casts = [
        'due_date' => 'date',
        'expected_amount' => 'decimal:2',
    ];

    // Relationships
    
    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    // Helper methods
    
    /**
     * Check if this schedule is overdue
     * A schedule is overdue if:
     * 1. The due date has passed
     * 2. It's not fully paid yet
     */
    public function isOverdue()
    {
        if ($this->due_date >= today()) {
            return false; // Not yet due
        }

        // Check if we have enough payments to cover this
        return $this->getAmountPaid() < $this->expected_amount;
    }

    /**
     * Check if this schedule is fully paid
     * Compare total payments received against expected amount
     */
    public function isPaid()
    {
        return $this->getAmountPaid() >= $this->expected_amount;
    }

    /**
     * Get total amount paid towards this specific schedule
     * This sums up all payments linked to this schedule
     */
    public function getAmountPaid()
    {
        return $this->payments()->sum('amount');
    }

    /**
     * Get the outstanding amount for this schedule
     * Outstanding = What we expect - What we've received
     */
    public function getOutstandingAmount()
    {
        $paid = $this->getAmountPaid();
        $outstanding = $this->expected_amount - $paid;
        
        return max(0, $outstanding); // Never return negative
    }

    /**
     * Update the status based on current state
     * This should be called after payments are recorded
     */
    public function updateStatus()
    {
        if ($this->isPaid()) {
            $this->status = 'paid';
        } elseif ($this->isOverdue()) {
            $this->status = 'overdue';
        } else {
            $this->status = 'pending';
        }
        
        $this->save();
    }
}
