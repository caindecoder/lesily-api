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
        static::saving(function (LesilyStoredEvent $lesilyStoredEvent): void {
            $lesilyStoredEvent->company_id = data_get($lesilyStoredEvent->meta_data, 'company_id');
        });
    }

    /**
     * @param  Builder<LesilyStoredEvent>  $lesilyStoredEvents
     */
    public function scopeWhereForCompany(Builder $lesilyStoredEvents, string $companyId): void
    {
        $lesilyStoredEvents->where('company_id', $companyId);
    }
}
