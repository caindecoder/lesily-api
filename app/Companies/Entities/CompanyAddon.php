<?php

namespace App\Companies\Entities;

use App\Foundation\Billing\Enums\Addon;
use Illuminate\Database\Eloquent\Model;

/**
 * An add-on subscribed by a company. Chunk-1 records only which add-ons a company
 * holds (for the billing gate); the add-on lifecycle (subscribed_at/expires_at,
 * expiry enforcement) is owned by the future add-on-purchase UC.
 */
class CompanyAddon extends Model
{
    protected $table = 'company_addons';

    protected $fillable = [
        'company_id',
        'addon',
    ];

    protected $casts = [
        'addon' => Addon::class,
    ];
}
