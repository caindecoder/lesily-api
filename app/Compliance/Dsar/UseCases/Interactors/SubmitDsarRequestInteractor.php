<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;

class SubmitDsarRequestInteractor
{
    public const string REQUEST_FOR_OTHERS = 'dsar.request-for-others';

    public function __construct(private readonly PermissionEvaluator $permissions) {}

    public function execute(Company $company, User $actor, SubmitDsarRequestCommand $command): void
    {
        $subjectUserId = $command->subject_user_id ?? $actor->getKey();

        if ($subjectUserId !== $actor->getKey() && ! $this->permissions->canUserDo($actor, $company, self::REQUEST_FOR_OTHERS)) {
            throw new RoleInsufficientException(self::REQUEST_FOR_OTHERS);
        }

        if (! $this->isLiveMember($company, $subjectUserId)) {
            throw new ModelNotFoundException;
        }
    }

    private function isLiveMember(Company $company, string $userId): bool
    {
        return CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $userId)
            ->exists();
    }
}
