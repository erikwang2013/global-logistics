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
    '/^HT\d{10,14}$/i' => ['domestic', 'ht'],
    '/^DPK\d{8,12}$/i' => ['domestic', 'debon'],
    '/^DB\d{8,14}$/i' => ['domestic', 'debon'],
    '/^KY\d{8,14}$/i' => ['domestic', 'ky'],
    '/^ANE\d{8,14}$/i' => ['domestic', 'ane'],
    '/^DHL\d{10,15}$/i' => ['international', 'dhl'],
    '/^1Z[0-9A-Z]{16}$/i' => ['international', 'ups'],
    '/^[A-Z]{2}\d{9}GB$/i' => ['international', 'royal-mail'], // 必须在通用 FedEx 规则之前（XX...GB 同时匹配 /^[A-Z]{2}\d{9}[A-Z]{2}$/i）
    '/^[A-Z]{2}\d{9}JP$/i' => ['international', 'japan-post'], // 同上
    '/^[A-Z]{2}\d{9}[A-Z]{2}$/i' => ['international', 'fedex'],
    '/^AUP\d{8,12}$/i' => ['international', 'australia-post'],
    '/^3S[A-Z0-9]{11,13}$/i' => ['international', 'postnl'],
    '/^\d{16}$/' => ['international', 'canada-post'], // 纯 16 位数字（zto 13 位规则已在前，无冲突）
    '/^FEDEX\d{10,15}$/i' => ['international', 'fedex'],
    '/^GM\d{9}$/i' => ['international', 'dhl'],
    '/^LH\d{10,12}$/i' => ['international', 'dhl'],
    '/^94\d{16,22}$/' => ['international', 'usps'],
    '/^(?!77)\d{10}$/' => ['international', 'dhl'], // 纯 10 位数字 DHL 国际单号；负向断言排除 77 开头（国内旧式申通单号，10 位），避免误查 DHL API，77 开头 10 位落入 CarrierNotFoundException
];
