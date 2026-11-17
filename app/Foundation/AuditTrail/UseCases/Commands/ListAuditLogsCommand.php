<?php

namespace App\Foundation\AuditTrail\UseCases\Commands;

class ListAuditLogsCommand extends AuditLogsQueryCommand
{
    /**
     * @var list<string>
     */
    protected const array QUERY_KEY_WHITELIST = ['within', 'user', 'event', 'ip', 'page', 'per_page'];
}
