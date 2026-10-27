<?php

namespace App\Companies\Memberships\IO\Http\Controllers;

use Illuminate\Http\Request;
use App\Companies\Memberships\IO\Http\Resources\RoleResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Companies\Memberships\UseCases\Repositories\RoleRepositoryInterface;

class RolesController
{
    public function __construct(private readonly RoleRepositoryInterface $roles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return RoleResource::collection(
            $this->roles->listAssignableForCompany($request->company()->getKey())
        );
    }
}
