<?php

namespace App\Foundation\RolesPermissions\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RolePermission extends Model
{
    use HasUuids;

    protected $table = 'role_permission';

    protected $fillable = [
        'id',
        'role_id',
        'permission_id',
    ];
}
