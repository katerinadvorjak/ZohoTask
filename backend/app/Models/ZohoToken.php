<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoToken extends Model
{
    protected $fillable = [
        'key',
        'access_token',
        'refresh_token',
        'expires_at',
        'api_domain',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
