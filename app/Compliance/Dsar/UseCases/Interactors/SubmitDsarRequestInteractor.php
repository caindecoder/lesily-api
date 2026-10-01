<?php

namespace App\Compliance\Dsar\UseCases\Interactors;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Companies\Entities\Company;
use App\Companies\Entities\CompanyUser;
use App\Compliance\Dsar\Aggregates\DsarRequest;
use App\Foundation\RolesPermissions\Entities\Role;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Foundation\Http\Exceptions\RoleInsufficientException;
use App\Foundation\RolesPermissions\Services\PermissionEvaluator;
use App\Compliance\Dsar\Exceptions\DsarRequestInProgressException;
use App\Compliance\Dsar\UseCases\Commands\SubmitDsarRequestCommand;
use App\Compliance\Dsar\UseCases\Repositories\DsarRequestRepositoryInterface;

class SubmitDsarRequestInteractor
{
    public const string REQUEST_FOR_OTHERS = 'dsar.request-for-others';

    public function __construct(
        private readonly PermissionEvaluator $permissions,
        private readonly DsarRequestRepositoryInterface $requests,
    ) {}

    public function execute(Company $company, User $actor, SubmitDsarRequestCommand $command): void
    {
        if ($command->isForSomeoneElse() && ! $this->permissions->canUserDo($actor, $company, self::REQUEST_FOR_OTHERS)) {
            throw new RoleInsufficientException(self::REQUEST_FOR_OTHERS);
        }

        $subjectMembership = $this->liveMembership($company, $command->subjectUserId());
        if ($subjectMembership === null) {
            throw new ModelNotFoundException;
        }

        try {
            DB::transaction(fn () => $this->record($company, $actor, $command));
        } catch (UniqueConstraintViolationException) {
            throw new DsarRequestInProgressException;
        }
    }

    private function record(Company $company, User $actor, SubmitDsarRequestCommand $command): void
    {
        if ($this->requests->findOpenFor($company->getKey(), $command->subjectUserId()) !== null) {
            throw new DsarRequestInProgressException;
        }

        DsarRequest::retrieve(Str::uuid7()->toString())
            ->submit($command, $this->roleCodeOf($company, $actor))
            ->persist();
    }

    private function liveMembership(Company $company, string $userId): ?CompanyUser
    {
        return CompanyUser::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $userId)
            ->first();
    }

    private function roleCodeOf(Company $company, User $actor): string
    {
        $roleId = $this->liveMembership($company, $actor->getKey())?->role_id;

        return Role::query()->whereKey($roleId)->value('code');
    }
}
