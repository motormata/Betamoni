<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanProduct extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'principal_amount',
        'interest_rate',
        'duration_days',
        'repayment_frequency', // daily, weekly, bi-weekly, monthly
        'is_active',
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }
}
