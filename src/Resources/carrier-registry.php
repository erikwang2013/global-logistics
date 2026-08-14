<?php

declare(strict_types=1);

use GlobalLogistics\Carriers\Domestic\Ems;
use GlobalLogistics\Carriers\Domestic\Jd;
use GlobalLogistics\Carriers\Domestic\Jt;
use GlobalLogistics\Carriers\Domestic\Sf;
use GlobalLogistics\Carriers\Domestic\Sto;
use GlobalLogistics\Carriers\Domestic\Yd;
use GlobalLogistics\Carriers\Domestic\Yto;
use GlobalLogistics\Carriers\Domestic\Zto;
use GlobalLogistics\Carriers\International\Dhl;
use GlobalLogistics\Carriers\International\FedEx;
use GlobalLogistics\Carriers\International\Ups;
use GlobalLogistics\Carriers\International\Usps;

// channel => code => adapter class
return [
    'domestic' => [
        'sf' => Sf::class,
        'zto' => Zto::class,
        'yto' => Yto::class,
        'jt' => Jt::class,
        'yd' => Yd::class,
        'sto' => Sto::class,
        'jd' => Jd::class,
        'ems' => Ems::class,
    ],
    'international' => [
        'dhl' => Dhl::class,
        'fedex' => FedEx::class,
        'ups' => Ups::class,
        'usps' => Usps::class,
    ],
];
