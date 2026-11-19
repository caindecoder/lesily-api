<?php

namespace App\Companies\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Companies\IO\Http\Resources\DataProtectionOfficerResource;
use App\Companies\UseCases\Commands\SetDataProtectionOfficerCommand;
use App\Companies\UseCases\Commands\RemoveDataProtectionOfficerCommand;
use App\Companies\UseCases\Interactors\SetDataProtectionOfficerInteractor;
use App\Companies\UseCases\Interactors\RemoveDataProtectionOfficerInteractor;

class CompanyDataProtectionOfficerController
{
    public function show(Request $request): DataProtectionOfficerResource
    {
        $officer = $request->company()->dataProtectionOfficer ?? throw new ModelNotFoundException;

        return new DataProtectionOfficerResource($officer);
    }

    public function update(Request $request, SetDataProtectionOfficerInteractor $interactor): DataProtectionOfficerResource
    {
        $officer = $interactor->execute(SetDataProtectionOfficerCommand::fromRequest($request), RequestOrigin::fromRequest($request));

        return new DataProtectionOfficerResource($officer);
    }

    public function destroy(Request $request, RemoveDataProtectionOfficerInteractor $interactor): Response
    {
        $command = RemoveDataProtectionOfficerCommand::validateAndCreate([
            'company' => $request->company(),
            'removedBy' => $request->user(),
        ]);

        $interactor->execute($command, RequestOrigin::fromRequest($request));

        return response()->noContent();
    }
}
