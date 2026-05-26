<?php

namespace App\Models;

use App\Foundation\Billing\Enums\Addon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CompanyAddon extends Model
{
    use HasUuids;

    protected $table = 'company_addons';

    protected $fillable = [
        'id',
        'company_id',
        'addon',
        'subscribed_at',
        'expires_at',
    ];

    protected $casts = [
        'addon' => Addon::class,
        'subscribed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
