<?php

namespace App\Compliance\Breach\UseCases\Commands;

use App\Foundation\UseCases\Commands\QueryCommand;

class ListBreachNoticesCommand extends QueryCommand
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['page', 'per_page'];
}
