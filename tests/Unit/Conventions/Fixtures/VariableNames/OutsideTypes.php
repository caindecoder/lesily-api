<?php

namespace Tests\Unit\Conventions\Fixtures\VariableNames;

use Closure;
use App\Companies\Entities\CompanyUser;
use Illuminate\Database\Eloquent\Builder;
use App\Foundation\AuditTrail\Values\RequestOrigin;

final class OutsideTypes
{
    public function accept(
        string $x,
        Closure $callback,
        callable $then,
        mixed $value,
        object $thing,
        RequestOrigin|CompanyUser $either,
        Builder $builder,
    ): void {
        //
    }
}
