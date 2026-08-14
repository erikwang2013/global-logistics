<?php

declare(strict_types=1);

namespace GlobalLogistics\Support;

enum TrackStatus: string
{
    case PENDING = 'PENDING';
    case IN_TRANSIT = 'IN_TRANSIT';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case DELIVERED = 'DELIVERED';
    case EXCEPTION = 'EXCEPTION';
    case RETURNED = 'RETURNED';
    case UNKNOWN = 'UNKNOWN';
}
