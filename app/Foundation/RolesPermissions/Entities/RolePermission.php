<?php

namespace App\Foundation\RolesPermissions\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RolePermission extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'role_permission';

    protected $fillable = [
        'id',
        'role_id',
        'permission_id',
    ];
}
