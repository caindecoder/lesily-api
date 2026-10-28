<?php

namespace App\Compliance\Privacy\Enums;

enum ThirdParty: string
{
    case HostingProvider = 'hosting_provider';
    case PaymentProcessor = 'payment_processor';
    case ErrorMonitoring = 'error_monitoring';
    case EmailDeliveryService = 'email_delivery_service';
}
