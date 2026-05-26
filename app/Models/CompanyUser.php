<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CompanyUser extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'company_user';

    protected $keyType = 'string';

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['id'];
    }
}
