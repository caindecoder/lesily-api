<?php

namespace App\Foundation\RolesPermissions\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Permission extends Model
{
    use HasUuids;

    protected $table = 'permissions';

    protected $fillable = [
        'id',
        'code',
        'name',
        'description',
        'module',
    ];
}
