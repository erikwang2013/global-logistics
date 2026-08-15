<?php

declare(strict_types=1);

use GlobalLogistics\Carriers\Domestic\Ane;
use GlobalLogistics\Carriers\Domestic\Cainiao;
use GlobalLogistics\Carriers\Domestic\ChinaPost;
use GlobalLogistics\Carriers\Domestic\Cre;
use GlobalLogistics\Carriers\Domestic\Dainiao;
use GlobalLogistics\Carriers\Domestic\Debon;
use GlobalLogistics\Carriers\Domestic\Ems;
use GlobalLogistics\Carriers\Domestic\Ht;
use GlobalLogistics\Carriers\Domestic\Jd;
use GlobalLogistics\Carriers\Domestic\Jt;
use GlobalLogistics\Carriers\Domestic\Ky;
use GlobalLogistics\Carriers\Domestic\Sf;
use GlobalLogistics\Carriers\Domestic\Sto;
use GlobalLogistics\Carriers\Domestic\Suning;
use GlobalLogistics\Carriers\Domestic\Sxjd;
use GlobalLogistics\Carriers\Domestic\Tiantian;
use GlobalLogistics\Carriers\Domestic\Uc;
use GlobalLogistics\Carriers\Domestic\Yd;
use GlobalLogistics\Carriers\Domestic\Ymd;
use GlobalLogistics\Carriers\Domestic\Yto;
use GlobalLogistics\Carriers\Domestic\Zjs;
use GlobalLogistics\Carriers\Domestic\Zto;
use GlobalLogistics\Carriers\Domestic\ZtoFreight;
use GlobalLogistics\Carriers\International\Aramex;
use GlobalLogistics\Carriers\International\AustraliaPost;
use GlobalLogistics\Carriers\International\Bpost;
use GlobalLogistics\Carriers\International\CainiaoIntl;
use GlobalLogistics\Carriers\International\CanadaPost;
use GlobalLogistics\Carriers\International\Correios;
use GlobalLogistics\Carriers\International\Correos;
use GlobalLogistics\Carriers\International\Dhl;
use GlobalLogistics\Carriers\International\Dpd;
use GlobalLogistics\Carriers\International\Evri;
use GlobalLogistics\Carriers\International\FedEx;
use GlobalLogistics\Carriers\International\FourPx;
use GlobalLogistics\Carriers\International\Gls;
use GlobalLogistics\Carriers\International\HongKongPost;
use GlobalLogistics\Carriers\International\JapanPost;
use GlobalLogistics\Carriers\International\Kerry;
use GlobalLogistics\Carriers\International\KoreaPost;
use GlobalLogistics\Carriers\International\LaPoste;
use GlobalLogistics\Carriers\International\NzPost;
use GlobalLogistics\Carriers\International\Ontrac;
use GlobalLogistics\Carriers\International\PosteItaliane;
use GlobalLogistics\Carriers\International\Postnl;
use GlobalLogistics\Carriers\International\Purolator;
use GlobalLogistics\Carriers\International\RoyalMail;
use GlobalLogistics\Carriers\International\RussiaPost;
use GlobalLogistics\Carriers\International\SfInternational;
use GlobalLogistics\Carriers\International\SingaporePost;
use GlobalLogistics\Carriers\International\SwissPost;
use GlobalLogistics\Carriers\International\Tnt;
use GlobalLogistics\Carriers\International\Ups;
use GlobalLogistics\Carriers\International\Usps;
use GlobalLogistics\Carriers\International\Yanwen;
use GlobalLogistics\Carriers\International\Yodel;
use GlobalLogistics\Carriers\International\YunExpress;

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
        'cainiao' => Cainiao::class,
        'china-post' => ChinaPost::class,
        'suning' => Suning::class,
        'uc' => Uc::class,
        'ymd' => Ymd::class,
        'zjs' => Zjs::class,
        'tiantian' => Tiantian::class,
        'zto-freight' => ZtoFreight::class,
        'dainiao' => Dainiao::class,
        'cre' => Cre::class,
        'sxjd' => Sxjd::class,
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
        'cainiao-intl' => CainiaoIntl::class,
        'correios' => Correios::class,
        'evri' => Evri::class,
        'fourpx' => FourPx::class,
        'hong-kong-post' => HongKongPost::class,
        'kerry' => Kerry::class,
        'korea-post' => KoreaPost::class,
        'la-poste' => LaPoste::class,
        'nz-post' => NzPost::class,
        'poste-italiane' => PosteItaliane::class,
        'russia-post' => RussiaPost::class,
        'singapore-post' => SingaporePost::class,
        'swiss-post' => SwissPost::class,
        'yodel' => Yodel::class,
        'yunexpress' => YunExpress::class,
        'yanwen' => Yanwen::class,
        'sf-international' => SfInternational::class,
        'tnt' => Tnt::class,
        'ontrac' => Ontrac::class,
        'purolator' => Purolator::class,
        'bpost' => Bpost::class,
        'correos' => Correos::class,
    ],
];
