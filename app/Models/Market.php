<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Market extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'region_id', 'name', 'code', 'address', 'lga', 'state', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function agents()
    {
        return $this->hasMany(User::class)->whereHas('roles', function($q) {
            $q->where('slug', 'agent');
        });
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
