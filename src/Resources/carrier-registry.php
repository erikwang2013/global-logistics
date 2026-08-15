<?php

declare(strict_types=1);

use GlobalLogistics\Carriers\Domestic\Ane;
use GlobalLogistics\Carriers\Domestic\Debon;
use GlobalLogistics\Carriers\Domestic\Ems;
use GlobalLogistics\Carriers\Domestic\Ht;
use GlobalLogistics\Carriers\Domestic\Jd;
use GlobalLogistics\Carriers\Domestic\Jt;
use GlobalLogistics\Carriers\Domestic\Ky;
use GlobalLogistics\Carriers\Domestic\Sf;
use GlobalLogistics\Carriers\Domestic\Sto;
use GlobalLogistics\Carriers\Domestic\Yd;
use GlobalLogistics\Carriers\Domestic\Yto;
use GlobalLogistics\Carriers\Domestic\Zto;
use GlobalLogistics\Carriers\International\Aramex;
use GlobalLogistics\Carriers\International\AustraliaPost;
use GlobalLogistics\Carriers\International\CanadaPost;
use GlobalLogistics\Carriers\International\Dhl;
use GlobalLogistics\Carriers\International\Dpd;
use GlobalLogistics\Carriers\International\FedEx;
use GlobalLogistics\Carriers\International\Gls;
use GlobalLogistics\Carriers\International\JapanPost;
use GlobalLogistics\Carriers\International\Postnl;
use GlobalLogistics\Carriers\International\RoyalMail;
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
        'ht' => Ht::class,
        'debon' => Debon::class,
        'ky' => Ky::class,
        'ane' => Ane::class,
    ],
    'international' => [
        'dhl' => Dhl::class,
        'fedex' => FedEx::class,
        'ups' => Ups::class,
        'usps' => Usps::class,
        'royal-mail' => RoyalMail::class,
        'canada-post' => CanadaPost::class,
        'australia-post' => AustraliaPost::class,
        'japan-post' => JapanPost::class,
        'aramex' => Aramex::class,
        'gls' => Gls::class,
        'dpd' => Dpd::class,
        'postnl' => Postnl::class,
    ],
];
