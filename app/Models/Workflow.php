<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    protected $fillable = [
        'name',
        'action',
        'status',
        'channel',
        'enabled',
        'include_owner',
        'recipients',
        'webhook_url',
        'webhook_secret',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'include_owner' => 'bool',
        'webhook_secret' => 'encrypted',
    ];
}
