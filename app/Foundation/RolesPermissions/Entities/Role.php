<?php

namespace App\Foundation\RolesPermissions\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Role extends Model
{
    use HasUuids;

    protected $table = 'roles';

    protected $fillable = [
        'id',
        'code',
        'name',
        'description',
        'scope',
        'is_assignable',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_assignable' => 'bool',
        'is_system' => 'bool',
        'sort_order' => 'int',
    ];
}
