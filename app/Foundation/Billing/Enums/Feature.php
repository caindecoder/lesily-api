<?php

namespace App\Foundation\Billing\Enums;

enum Feature: string
{
    case TwoFactorAuth = 'two_factor_auth';
    case TemplateEngine = 'template_engine';
}
