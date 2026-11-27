<?php

namespace App\Compliance\Privacy\UseCases\Register;

class ProcessingRegister
{
    /**
     * @param  list<ProcessingActivityProvider>  $providers
     */
    public function __construct(private readonly array $providers) {}

    /**
     * Every module's processing activities, in binding order.
     *
     * @return list<ProcessingActivity>
     */
    public function activities(): array
    {
        $activities = [];

        foreach ($this->providers as $processingActivityProvider) {
            array_push($activities, ...$processingActivityProvider->activities());
        }

        return $activities;
    }
}
