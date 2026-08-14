<?php

declare(strict_types=1);

// pattern => [channel, carrierCode]；channel 取值 'domestic' | 'international'
return [
    '/^SF\d{10,12}$/i' => ['domestic', 'sf'],
    '/^JT\d{10,15}$/i' => ['domestic', 'jt'],
    '/^\d{13}$/' => ['domestic', 'zto'],
    '/^YT\d{10,12}$/i' => ['domestic', 'yto'],
    '/^DHL\d{10,15}$/i' => ['international', 'dhl'],
    '/^1Z[0-9A-Z]{16}$/i' => ['international', 'ups'],
    '/^[A-Z]{2}\d{9}[A-Z]{2}$/i' => ['international', 'fedex'],
    '/^GM\d{9}$/i' => ['international', 'dhl'],
    '/^LH\d{10,12}$/i' => ['international', 'dhl'],
    '/^RR\d{12}$/i' => ['international', 'royal-mail'],
];
