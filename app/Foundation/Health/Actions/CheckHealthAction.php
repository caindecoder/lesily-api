<?php

namespace App\Foundation\Health\Actions;

use Exception;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use App\Foundation\Health\Enums\HealthStatus;

class CheckHealthAction
{
    public function execute(): array
    {
        $subsystems = [
            'app' => 'up',
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];

        $status = in_array('down', $subsystems, true)
            ? HealthStatus::Unhealthy
            : HealthStatus::Healthy;

        return [
            'status' => $status,
            'checked_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'subsystems' => $subsystems,
        ];
    }

    protected function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();

            return 'up';
        } catch (Exception $e) {
            report($e);

            return 'down';
        }
    }

    protected function checkRedis(): string
    {
        try {
            Redis::connection()->ping();

            return 'up';
        } catch (Exception $e) {
            report($e);

            return 'down';
        }
    }

    protected function checkQueue(): string
    {
        try {
            Queue::size();

            return 'up';
        } catch (Exception $e) {
            report($e);

            return 'down';
        }
    }
}
