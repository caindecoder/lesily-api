<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ShowDsarRequestInteractor
{
    public function execute(string $requestId, User $actor, Company $company): DsarRequestRecord
    {
        return DsarRequestRecord::query()->whereKey($requestId)->first()
            ?? throw new ModelNotFoundException;
    }
}
