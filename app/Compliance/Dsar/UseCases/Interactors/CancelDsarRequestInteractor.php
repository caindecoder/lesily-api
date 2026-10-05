<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Compliance\Dsar\Entities\DsarRequestRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Compliance\Dsar\UseCases\Queries\DsarRequestVisibility;
use App\Compliance\Dsar\UseCases\Commands\CancelDsarRequestCommand;

class CancelDsarRequestInteractor
{
    public function __construct(private readonly DsarRequestVisibility $visibility) {}

    public function execute(CancelDsarRequestCommand $command, User $actor): DsarRequestRecord
    {
        $company = Company::query()->findOrFail($command->companyId);

        return $this->visibility->visibleTo($actor, $company)->whereKey($command->requestId)->first()
            ?? throw new ModelNotFoundException;
    }
}
