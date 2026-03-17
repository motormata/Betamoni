<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Region extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['name', 'code', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function markets()
    {
        return $this->hasMany(Market::class);
    }

    public function activeMarkets()
    {
        return $this->hasMany(Market::class)->where('is_active', true);
    }
}