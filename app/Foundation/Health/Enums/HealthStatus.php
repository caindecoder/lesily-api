<?php

namespace App\Foundation\Health\Enums;

enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Unhealthy = 'unhealthy';
}
