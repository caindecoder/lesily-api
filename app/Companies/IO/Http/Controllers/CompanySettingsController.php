<?php

namespace App\Companies\IO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Companies\Enums\ResettableSetting;
use App\Foundation\AuditTrail\Values\RequestOrigin;
use App\Companies\IO\Http\Resources\CompanySettingsResource;
use App\Companies\UseCases\Commands\ResetCompanySettingCommand;
use App\Companies\UseCases\Interactors\ResetCompanySettingInteractor;

/**
 * The company's settings view (UC-1-056 / 057) — the company AuthorizeCompany
 * resolved, shaped by CompanySettingsResource.
 */
class CompanySettingsController
{
    public function __construct(private readonly ResetCompanySettingInteractor $resetSetting) {}

    public function show(Request $request): CompanySettingsResource
    {
        return new CompanySettingsResource($request->company());
    }

    public function destroy(Request $request, string $companyId, ResettableSetting $setting): Response
    {
        $this->resetSetting->execute(ResetCompanySettingCommand::validateAndCreate([
            'company' => $companyId,
            'setting' => $setting,
            'resetBy' => $request->user(),
        ]), RequestOrigin::fromRequest($request));

        return response()->noContent();
    }
}
