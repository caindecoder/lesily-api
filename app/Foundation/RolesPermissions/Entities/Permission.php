<?php

namespace App\Foundation\RolesPermissions\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Foundation\RolesPermissions\PermissionFactory;

class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'permissions';

    protected $fillable = [
        'id',
        'code',
        'name',
        'description',
        'module',
    ];

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return PermissionFactory::new();
    }
}
