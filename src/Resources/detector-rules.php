<?php

declare(strict_types=1);

// pattern => [channel, carrierCode]；channel 取值 'domestic' | 'international'
return [
    '/^SF\d{10,12}$/i' => ['domestic', 'sf'],
    '/^JT\d{10,15}$/i' => ['domestic', 'jt'],
    '/^YD\d{8,16}$/i' => ['domestic', 'yd'],
    '/^77\d{11}$/' => ['domestic', 'sto'], // 必须在 /^\d{13}$/ 之前，否则 77 开头 13 位数字误判为 zto
    '/^\d{13}$/' => ['domestic', 'zto'],
    '/^YT\d{10,12}$/i' => ['domestic', 'yto'],
    '/^JD[A-Z0-9]{8,18}$/i' => ['domestic', 'jd'],
    '/^E[A-Z]\d{9}CN$/i' => ['domestic', 'ems'], // 必须在通用 FedEx 规则之前（EA...CN 同时匹配 /^[A-Z]{2}\d{9}[A-Z]{2}$/i）
    '/^DHL\d{10,15}$/i' => ['international', 'dhl'],
    '/^1Z[0-9A-Z]{16}$/i' => ['international', 'ups'],
    '/^[A-Z]{2}\d{9}[A-Z]{2}$/i' => ['international', 'fedex'],
    '/^FEDEX\d{10,15}$/i' => ['international', 'fedex'],
    '/^GM\d{9}$/i' => ['international', 'dhl'],
    '/^LH\d{10,12}$/i' => ['international', 'dhl'],
    '/^94\d{16,22}$/' => ['international', 'usps'],
    '/^\d{10}$/' => ['international', 'dhl'], // 纯 10 位数字误命中风险：DHL 纯数字单号，需用户确认
    '/^RR\d{12}$/i' => ['international', 'royal-mail'],
];
