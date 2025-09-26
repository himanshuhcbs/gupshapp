<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscription extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'user_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'payment_method_id',
        'status',
        'current_period_start',
        'current_period_end',
        'cancel_at',
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
