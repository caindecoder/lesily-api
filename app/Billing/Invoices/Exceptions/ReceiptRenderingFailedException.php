<?php

namespace App\Billing\Invoices\Exceptions;

use RuntimeException;

/**
 * The PDF renderer could not produce a complete receipt (UC-1-121 error flow
 * 7b) — rendered as the 503 RECEIPT_GENERATION_UNAVAILABLE envelope with
 * Retry-After: 30 (s135 F-d); the internal cause stays out of the response.
 */
class ReceiptRenderingFailedException extends RuntimeException {}
