<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Dsar\UseCases\Queries\DsarRequestVisibility;

class ShowDsarRequestInteractor
{
    public function __construct(private readonly DsarRequestVisibility $visibility) {}

    public function execute(string $requestId, User $actor, Company $company): DsarRequestRecord
    {
        return $this->visibility->visibleTo($actor, $company)->whereKey($requestId)->first()
            ?? throw new ModelNotFoundException;
    }
}
