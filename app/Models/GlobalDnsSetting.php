<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One encrypted DNS account shared only by nodes that explicitly opt in. */
class GlobalDnsSetting extends Model
{
    protected $table = 'global_dns_settings';
    protected $guarded = ['id'];
    protected $hidden = ['dns_env'];
    protected $casts = [
        'dns_env' => 'encrypted:array',
        'revision' => 'integer',
    ];
}
