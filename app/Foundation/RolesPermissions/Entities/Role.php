<?php

namespace App\Foundation\RolesPermissions\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Database\Factories\Foundation\RolesPermissions\RoleFactory;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

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

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permission',
            'role_id',
            'permission_id'
        );
    }

    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', $code)->first();
    }

    /**
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return RoleFactory::new();
    }
}
