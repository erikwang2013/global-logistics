<?php

declare(strict_types=1);

// pattern => [channel, carrierCode]；channel 取值 'domestic' | 'international'
// 顺序即优先级：先具体后通用；数字位数规则互斥，但必须放在字母前缀规则之后（数字单号不会匹配字母规则，反之亦然）
return [
    '/^SF\d{10,12}$/i' => ['domestic', 'sf'],
    '/^JT\d{10,15}$/i' => ['domestic', 'jt'],
    '/^YD\d{8,16}$/i' => ['domestic', 'yd'],
    '/^77\d{11}$/' => ['domestic', 'sto'], // 必须在 /^\d{13}$/ 之前，否则 77 开头 13 位数字误判为 zto
    '/^3\d{12}$/' => ['domestic', 'zjs'], // 宅急送 13 位以 3 开头（中通 13 位以 7/1/0/9 开头为主），必须在 /^\d{13}$/ 之前
    '/^\d{13}$/' => ['domestic', 'zto'],
    '/^\d{12}$/' => ['domestic', 'uc'], // 优速 12 位纯数字；与 zto(13) / dhl(10) / canada-post(16) 互斥
    '/^\d{20}$/' => ['domestic', 'suning'], // 苏宁 20 位纯数字，与其余位数规则互斥
    '/^YT\d{10,12}$/i' => ['domestic', 'yto'],
    '/^YT\d{13,14}$/i' => ['international', 'yunexpress'], // 云途 YT+13/14 位；10-12 位已被 yto 规则截获
    '/^JD0\d{15}$/i' => ['international', 'yodel'], // 必须在京东规则之前（Yodel JD0 开头 16 位数字同时匹配 /^JD[A-Z0-9]{8,18}$/i）
    '/^JD[A-Z0-9]{8,18}$/i' => ['domestic', 'jd'],
    '/^CA\d{9,12}$/i' => ['domestic', 'cainiao'],
    '/^YMD\d{9,14}$/i' => ['domestic', 'ymd'],
    '/^E[A-Z]\d{9}CN$/i' => ['domestic', 'ems'], // 必须在通用 FedEx 规则之前（EA...CN 同时匹配 /^[A-Z]{2}\d{9}[A-Z]{2}$/i）
    '/^R[A-Z]\d{9}CN$/i' => ['domestic', 'china-post'], // 中国邮政国际小包挂号（RA...CN），同上需在通用 FedEx 规则之前
    '/^HT\d{10,14}$/i' => ['domestic', 'ht'],
    '/^DPK\d{8,12}$/i' => ['domestic', 'debon'],
    '/^DB\d{8,14}$/i' => ['domestic', 'debon'],
    '/^KY\d{8,14}$/i' => ['domestic', 'ky'],
    '/^ANE\d{8,14}$/i' => ['domestic', 'ane'],
    '/^DHL\d{10,15}$/i' => ['international', 'dhl'],
    '/^1Z[0-9A-Z]{16}$/i' => ['international', 'ups'],
    '/^LP\d{14,20}$/i' => ['international', 'cainiao-intl'], // 菜鸟国际 LP 开头
    '/^4PX\d{10,15}$/i' => ['international', 'fourpx'],
    '/^TV\d{14}$/i' => ['international', 'evri'], // Evri(Hermes) TV 开头 14 位
    '/^KK\d{10,12}[A-Z]{2}$/i' => ['international', 'kerry'], // 嘉里（泰国/东南亚）KK 开头
    // 各国邮政 S10 挂号格式（XX + 9 位 + 国家码），全部必须在通用 FedEx 规则之前
    '/^[A-Z]{2}\d{9}GB$/i' => ['international', 'royal-mail'], // 必须在通用 FedEx 规则之前（XX...GB 同时匹配 /^[A-Z]{2}\d{9}[A-Z]{2}$/i）
    '/^[A-Z]{2}\d{9}JP$/i' => ['international', 'japan-post'], // 同上
    '/^[A-Z]{2}\d{9}BR$/i' => ['international', 'correios'], // 巴西邮政，同上
    '/^[A-Z]{2}\d{9}HK$/i' => ['international', 'hong-kong-post'], // 香港邮政，同上
    '/^[A-Z]{2}\d{9}KR$/i' => ['international', 'korea-post'], // 韩国邮政，同上
    '/^[A-Z]{2}\d{9}FR$/i' => ['international', 'la-poste'], // 法国邮政，同上
    '/^[A-Z]{2}\d{9}NZ$/i' => ['international', 'nz-post'], // 新西兰邮政，同上
    '/^[A-Z]{2}\d{9}IT$/i' => ['international', 'poste-italiane'], // 意大利邮政，同上
    '/^[A-Z]{2}\d{9}RU$/i' => ['international', 'russia-post'], // 俄罗斯邮政，同上
    '/^[A-Z]{2}\d{9}SG$/i' => ['international', 'singapore-post'], // 新加坡邮政，同上
    '/^[A-Z]{2}\d{9}CH$/i' => ['international', 'swiss-post'], // 瑞士邮政，同上
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
