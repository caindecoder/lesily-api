<?php

namespace App\Foundation\EventStore\StoredEvents\Models;

use Illuminate\Database\Eloquent\Builder;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent as BaseEloquentStoredEvent;

/**
 * @property string $event_class
 * @property string|null $company_id
 */
class LesilyStoredEvent extends BaseEloquentStoredEvent
{
    protected static function booted(): void
    {
        static::saving(function (LesilyStoredEvent $storedEvent): void {
            $storedEvent->company_id = data_get($storedEvent->meta_data, 'company_id');
        });
    }

    /**
     * @param  Builder<LesilyStoredEvent>  $query
     */
    public function scopeWhereForCompany(Builder $query, string $companyId): void
    {
        $query->where('company_id', $companyId);
    }
}
