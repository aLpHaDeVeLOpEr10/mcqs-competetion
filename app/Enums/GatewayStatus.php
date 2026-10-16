<?php

namespace App\Enums;

/**
 * A provider's answer about one payment, normalized by the gateway driver.
 */
enum GatewayStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case NotFound = 'not_found';
}
