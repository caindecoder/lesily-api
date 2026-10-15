<?php

namespace Tests\Unit\Conventions\Fixtures\ModelKeyReads;

use stdClass;

final class RowRead
{
    public function keyOf(stdClass $row): mixed
    {
        return $row->id;
    }
}
