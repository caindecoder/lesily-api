<?php

namespace App\Compliance\Dsar\UseCases\Repositories;

use App\Compliance\Dsar\Entities\DsarRequestRecord;

interface DsarRequestRepositoryInterface
{
    public function findOpenFor(string $companyId, string $subjectUserId): ?DsarRequestRecord;
}
