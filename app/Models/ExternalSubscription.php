<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalSubscription extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['url'];
    protected $casts = [
        'url' => 'encrypted',
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(ExternalSubscriptionNode::class, 'source_id');
    }
}
