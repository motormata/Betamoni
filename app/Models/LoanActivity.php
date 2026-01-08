<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanActivity extends Model
{
    protected $fillable = [
        'loan_id', 'user_id', 'action', 'description', 'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}