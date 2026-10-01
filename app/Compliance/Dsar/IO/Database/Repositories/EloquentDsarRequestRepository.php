<?php

namespace App\Compliance\Dsar\IO\Database\Repositories;

use App\Compliance\Dsar\Entities\DsarRequestRecord;
use App\Compliance\Dsar\UseCases\Repositories\DsarRequestRepositoryInterface;

class EloquentDsarRequestRepository implements DsarRequestRepositoryInterface
{
    public function findOpenFor(string $companyId, string $subjectUserId): ?DsarRequestRecord
    {
        return DsarRequestRecord::query()
            ->where('company_id', $companyId)
            ->where('subject_user_id', $subjectUserId)
            ->open()
            ->first();
    }
}
