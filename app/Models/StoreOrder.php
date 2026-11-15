<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreOrder extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['product', 'checkout_url'];

    protected function casts(): array
    {
        return ['product' => 'array', 'erp_result' => 'array', 'paid_at' => 'datetime', 'fulfilled_at' => 'datetime', 'amount' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
