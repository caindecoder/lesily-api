<?php

namespace App\Compliance\Privacy\UseCases\Register;

interface ProcessingActivityProvider
{
    /**
     * @return list<ProcessingActivity>
     */
    public function activities(): array;
}
