<?php

namespace App\Foundation\RolesPermissions\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Foundation\RolesPermissions\RolePermissionFactory;

class RolePermission extends Model
{
    /** @use HasFactory<RolePermissionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'role_permission';

    protected $fillable = [
        'id',
        'role_id',
        'permission_id',
    ];

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return RolePermissionFactory::new();
    }
}
