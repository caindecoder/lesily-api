<?php

namespace App\Foundation\UseCases\Attributes;

use Attribute;

/**
 * A command property that deliberately stays a string (conv-plan §2.24): the reason says
 * why it is not a model, an enum or a date.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class OpaqueString
{
    public function __construct(public string $reason) {}
}
