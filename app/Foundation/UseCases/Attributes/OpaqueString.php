<?php

namespace App\Foundation\UseCases\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OpaqueString
{
    public function __construct(public string $reason) {}
}
