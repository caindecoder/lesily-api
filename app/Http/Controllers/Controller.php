<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '0.1.0',
    title: 'Lesily HRMS API',
    description: 'Laravel 13 API backend for the Lesily HRMS SaaS platform (FusionRealm). Auth-walled OpenAPI 3.x spec emitted via L5-Swagger.',
)]
#[OA\Server(
    url: 'https://api.lesily.com',
    description: 'Production',
)]
#[OA\Server(
    url: 'https://api-staging.lesily.com',
    description: 'Staging',
)]
abstract class Controller
{
    //
}
