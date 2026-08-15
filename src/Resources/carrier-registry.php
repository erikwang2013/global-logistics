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
use GlobalLogistics\Carriers\Domestic\Lht;
use GlobalLogistics\Carriers\Domestic\Rrs;
use GlobalLogistics\Carriers\Domestic\Sf;
use GlobalLogistics\Carriers\Domestic\Sto;
use GlobalLogistics\Carriers\Domestic\Suning;
use GlobalLogistics\Carriers\Domestic\Sure;
use GlobalLogistics\Carriers\Domestic\Sxjd;
use GlobalLogistics\Carriers\Domestic\Tiantian;
use GlobalLogistics\Carriers\Domestic\Uc;
use GlobalLogistics\Carriers\Domestic\Xf;
use GlobalLogistics\Carriers\Domestic\Yd;
use GlobalLogistics\Carriers\Domestic\Ymd;
use GlobalLogistics\Carriers\Domestic\Yto;
use GlobalLogistics\Carriers\Domestic\Zjs;
use GlobalLogistics\Carriers\Domestic\Zto;
use GlobalLogistics\Carriers\Domestic\ZtoFreight;
use GlobalLogistics\Carriers\Domestic\Fengwang;
use GlobalLogistics\Carriers\Domestic\HtFreight;
use GlobalLogistics\Carriers\Domestic\YdFreight;
use GlobalLogistics\Carriers\Domestic\YtoFreight;
use GlobalLogistics\Carriers\Domestic\Zy;
use GlobalLogistics\Carriers\Domestic\Cae;
use GlobalLogistics\Carriers\Domestic\Huayu;
use GlobalLogistics\Carriers\Domestic\Jiaji;
use GlobalLogistics\Carriers\Domestic\Longbang;
use GlobalLogistics\Carriers\Domestic\Qy;
use GlobalLogistics\Carriers\Domestic\Suteng;
use GlobalLogistics\Carriers\Domestic\Zhongtie;
use GlobalLogistics\Carriers\Domestic\Guotong;
use GlobalLogistics\Carriers\Domestic\Quanfeng;
use GlobalLogistics\Carriers\Domestic\Xinbang;
use GlobalLogistics\Carriers\Domestic\Yuancheng;
use GlobalLogistics\Carriers\Domestic\Zengyi;
use GlobalLogistics\Carriers\Domestic\Zhongyou;
use GlobalLogistics\Carriers\International\Aramex;
use GlobalLogistics\Carriers\International\AustraliaPost;
use GlobalLogistics\Carriers\International\AustrianPost;
use GlobalLogistics\Carriers\International\Bpost;
use GlobalLogistics\Carriers\International\Bring;
use GlobalLogistics\Carriers\International\ChunghwaPost;
use GlobalLogistics\Carriers\International\Delhivery;
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
use GlobalLogistics\Carriers\International\InPost;
use GlobalLogistics\Carriers\International\JapanPost;
use GlobalLogistics\Carriers\International\Kerry;
use GlobalLogistics\Carriers\International\KoreaPost;
use GlobalLogistics\Carriers\International\LaPoste;
use GlobalLogistics\Carriers\International\NzPost;
use GlobalLogistics\Carriers\International\Omniva;
use GlobalLogistics\Carriers\International\Ontrac;
use GlobalLogistics\Carriers\International\PosteItaliane;
use GlobalLogistics\Carriers\International\Posti;
use GlobalLogistics\Carriers\International\Postnl;
use GlobalLogistics\Carriers\International\Purolator;
use GlobalLogistics\Carriers\International\RoyalMail;
use GlobalLogistics\Carriers\International\RussiaPost;
use GlobalLogistics\Carriers\International\SfInternational;
use GlobalLogistics\Carriers\International\SingaporePost;
use GlobalLogistics\Carriers\International\SwissPost;
use GlobalLogistics\Carriers\International\ThailandPost;
use GlobalLogistics\Carriers\International\Tnt;
use GlobalLogistics\Carriers\International\Ups;
use GlobalLogistics\Carriers\International\Usps;
use GlobalLogistics\Carriers\International\Yanwen;
use GlobalLogistics\Carriers\International\Yodel;
use GlobalLogistics\Carriers\International\YunExpress;
use GlobalLogistics\Carriers\International\PostNord;
use GlobalLogistics\Carriers\International\Ctt;
use GlobalLogistics\Carriers\International\AnPost;
use GlobalLogistics\Carriers\International\PocztaPolska;
use GlobalLogistics\Carriers\International\IndiaPost;
use GlobalLogistics\Carriers\International\PosMalaysia;
use GlobalLogistics\Carriers\International\EmiratesPost;
use GlobalLogistics\Carriers\International\MagyarPosta;
use GlobalLogistics\Carriers\International\CeskaPosta;
use GlobalLogistics\Carriers\International\Elta;
use GlobalLogistics\Carriers\International\ViettelPost;
use GlobalLogistics\Carriers\International\ZtoIntl;
use GlobalLogistics\Carriers\International\YtoIntl;
use GlobalLogistics\Carriers\International\JtIntl;
use GlobalLogistics\Carriers\International\Winit;
use GlobalLogistics\Carriers\International\CorreoArgentino;
use GlobalLogistics\Carriers\International\CorreosChile;
use GlobalLogistics\Carriers\International\CorreosMexico;
use GlobalLogistics\Carriers\International\CroatiaPost;
use GlobalLogistics\Carriers\International\EgyptPost;
use GlobalLogistics\Carriers\International\IsraelPost;
use GlobalLogistics\Carriers\International\Kazpost;
use GlobalLogistics\Carriers\International\PakistanPost;
use GlobalLogistics\Carriers\International\PhlPost;
use GlobalLogistics\Carriers\International\PosIndonesia;
use GlobalLogistics\Carriers\International\RomaniaPost;
use GlobalLogistics\Carriers\International\SaudiPost;
use GlobalLogistics\Carriers\International\SouthAfricanPost;
use GlobalLogistics\Carriers\International\TurkeyPost;
use GlobalLogistics\Carriers\International\Ukrposhta;
use GlobalLogistics\Carriers\International\AlbaniaPost;
use GlobalLogistics\Carriers\International\BelarusPost;
use GlobalLogistics\Carriers\International\BosniaPost;
use GlobalLogistics\Carriers\International\BulgariaPost;
use GlobalLogistics\Carriers\International\CyprusPost;
use GlobalLogistics\Carriers\International\IcelandPost;
use GlobalLogistics\Carriers\International\LatviaPost;
use GlobalLogistics\Carriers\International\LithuaniaPost;
use GlobalLogistics\Carriers\International\LuxembourgPost;
use GlobalLogistics\Carriers\International\MacedoniaPost;
use GlobalLogistics\Carriers\International\MaltaPost;
use GlobalLogistics\Carriers\International\MoldovaPost;
use GlobalLogistics\Carriers\International\SerbiaPost;
use GlobalLogistics\Carriers\International\SlovakPost;
use GlobalLogistics\Carriers\International\SloveniaPost;
use GlobalLogistics\Carriers\International\AfghanistanPost;
use GlobalLogistics\Carriers\International\AlandPost;
use GlobalLogistics\Carriers\International\AlgeriaPost;
use GlobalLogistics\Carriers\International\AndorraPost;
use GlobalLogistics\Carriers\International\AngolaPost;
use GlobalLogistics\Carriers\International\ArmeniaPost;
use GlobalLogistics\Carriers\International\AzerbaijanPost;
use GlobalLogistics\Carriers\International\BahamasPost;
use GlobalLogistics\Carriers\International\BahrainPost;
use GlobalLogistics\Carriers\International\BangladeshPost;
use GlobalLogistics\Carriers\International\BarbadosPost;
use GlobalLogistics\Carriers\International\BhutanPost;
use GlobalLogistics\Carriers\International\BoliviaPost;
use GlobalLogistics\Carriers\International\BruneiPost;
use GlobalLogistics\Carriers\International\CambodiaPost;
use GlobalLogistics\Carriers\International\CameroonPost;
use GlobalLogistics\Carriers\International\ColombiaPost;
use GlobalLogistics\Carriers\International\CostaRicaPost;
use GlobalLogistics\Carriers\International\CubaPost;
use GlobalLogistics\Carriers\International\DeutschePost;
use GlobalLogistics\Carriers\International\DominicanPost;
use GlobalLogistics\Carriers\International\EcuadorPost;
use GlobalLogistics\Carriers\International\ElSalvadorPost;
use GlobalLogistics\Carriers\International\EthiopiaPost;
use GlobalLogistics\Carriers\International\FaroePost;
use GlobalLogistics\Carriers\International\FijiPost;
use GlobalLogistics\Carriers\International\GeorgiaPost;
use GlobalLogistics\Carriers\International\GhanaPost;
use GlobalLogistics\Carriers\International\GibraltarPost;
use GlobalLogistics\Carriers\International\GreenlandPost;
use GlobalLogistics\Carriers\International\GuatemalaPost;
use GlobalLogistics\Carriers\International\GuernseyPost;
use GlobalLogistics\Carriers\International\GuyanaPost;
use GlobalLogistics\Carriers\International\HondurasPost;
use GlobalLogistics\Carriers\International\IsleOfManPost;
use GlobalLogistics\Carriers\International\IvoryCoastPost;
use GlobalLogistics\Carriers\International\JamaicaPost;
use GlobalLogistics\Carriers\International\JerseyPost;
use GlobalLogistics\Carriers\International\KenyaPost;
use GlobalLogistics\Carriers\International\KuwaitPost;
use GlobalLogistics\Carriers\International\KyrgyzstanPost;
use GlobalLogistics\Carriers\International\LaosPost;
use GlobalLogistics\Carriers\International\LiechtensteinPost;
use GlobalLogistics\Carriers\International\MaldivesPost;
use GlobalLogistics\Carriers\International\MauritiusPost;
use GlobalLogistics\Carriers\International\MonacoPost;
use GlobalLogistics\Carriers\International\MongoliaPost;
use GlobalLogistics\Carriers\International\MontenegroPost;
use GlobalLogistics\Carriers\International\MoroccoPost;
use GlobalLogistics\Carriers\International\MozambiquePost;
use GlobalLogistics\Carriers\International\MyanmarPost;
use GlobalLogistics\Carriers\International\NepalPost;
use GlobalLogistics\Carriers\International\NicaraguaPost;
use GlobalLogistics\Carriers\International\NigeriaPost;
use GlobalLogistics\Carriers\International\PanamaPost;
use GlobalLogistics\Carriers\International\PapuaPost;
use GlobalLogistics\Carriers\International\ParaguayPost;
use GlobalLogistics\Carriers\International\PeruPost;
use GlobalLogistics\Carriers\International\QatarPost;
use GlobalLogistics\Carriers\International\RwandaPost;
use GlobalLogistics\Carriers\International\SamoaPost;
use GlobalLogistics\Carriers\International\SanMarinoPost;
use GlobalLogistics\Carriers\International\SenegalPost;
use GlobalLogistics\Carriers\International\SriLankaPost;
use GlobalLogistics\Carriers\International\SurinamePost;
use GlobalLogistics\Carriers\International\TajikistanPost;
use GlobalLogistics\Carriers\International\TanzaniaPost;
use GlobalLogistics\Carriers\International\TrinidadPost;
use GlobalLogistics\Carriers\International\TunisiaPost;
use GlobalLogistics\Carriers\International\TurkmenistanPost;
use GlobalLogistics\Carriers\International\UgandaPost;
use GlobalLogistics\Carriers\International\UruguayPost;
use GlobalLogistics\Carriers\International\UzbekistanPost;
use GlobalLogistics\Carriers\International\VaticanPost;
use GlobalLogistics\Carriers\International\VenezuelaPost;
use GlobalLogistics\Carriers\International\ZambiaPost;
use GlobalLogistics\Carriers\International\ZimbabwePost;

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
        'lht' => Lht::class,
        'rrs' => Rrs::class,
        'sure' => Sure::class,
        'xf' => Xf::class,
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
        'fengwang' => Fengwang::class,
        'ht-freight' => HtFreight::class,
        'yd-freight' => YdFreight::class,
        'yto-freight' => YtoFreight::class,
        'zy' => Zy::class,
        'cae' => Cae::class,
        'huayu' => Huayu::class,
        'jiaji' => Jiaji::class,
        'longbang' => Longbang::class,
        'qy' => Qy::class,
        'suteng' => Suteng::class,
        'zhongtie' => Zhongtie::class,
        'zhongyou' => Zhongyou::class,
        'zengyi' => Zengyi::class,
        'quanfeng' => Quanfeng::class,
        'guotong' => Guotong::class,
        'yuancheng' => Yuancheng::class,
        'xinbang' => Xinbang::class,
    ],
    'international' => [
        'dhl' => Dhl::class,
        'fedex' => FedEx::class,
        'ups' => Ups::class,
        'usps' => Usps::class,
        'royal-mail' => RoyalMail::class,
        'canada-post' => CanadaPost::class,
        'australia-post' => AustraliaPost::class,
        'austrian-post' => AustrianPost::class,
        'bring' => Bring::class,
        'chunghwa-post' => ChunghwaPost::class,
        'delhivery' => Delhivery::class,
        'inpost' => InPost::class,
        'omniva' => Omniva::class,
        'posti' => Posti::class,
        'thailand-post' => ThailandPost::class,
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
        'postnord' => PostNord::class,
        'ctt' => Ctt::class,
        'an-post' => AnPost::class,
        'poczta-polska' => PocztaPolska::class,
        'india-post' => IndiaPost::class,
        'pos-malaysia' => PosMalaysia::class,
        'emirates-post' => EmiratesPost::class,
        'magyar-posta' => MagyarPosta::class,
        'ceska-posta' => CeskaPosta::class,
        'elta' => Elta::class,
        'viettel-post' => ViettelPost::class,
        'zto-intl' => ZtoIntl::class,
        'yto-intl' => YtoIntl::class,
        'jt-intl' => JtIntl::class,
        'winit' => Winit::class,
        'ukrposhta' => Ukrposhta::class,
        'turkey-post' => TurkeyPost::class,
        'israel-post' => IsraelPost::class,
        'egypt-post' => EgyptPost::class,
        'saudi-post' => SaudiPost::class,
        'south-african-post' => SouthAfricanPost::class,
        'correos-mexico' => CorreosMexico::class,
        'correo-argentino' => CorreoArgentino::class,
        'correos-chile' => CorreosChile::class,
        'pos-indonesia' => PosIndonesia::class,
        'phl-post' => PhlPost::class,
        'pakistan-post' => PakistanPost::class,
        'kazpost' => Kazpost::class,
        'romania-post' => RomaniaPost::class,
        'croatia-post' => CroatiaPost::class,
        'slovak-post' => SlovakPost::class,
        'slovenia-post' => SloveniaPost::class,
        'serbia-post' => SerbiaPost::class,
        'bulgaria-post' => BulgariaPost::class,
        'lithuania-post' => LithuaniaPost::class,
        'latvia-post' => LatviaPost::class,
        'iceland-post' => IcelandPost::class,
        'malta-post' => MaltaPost::class,
        'luxembourg-post' => LuxembourgPost::class,
        'cyprus-post' => CyprusPost::class,
        'moldova-post' => MoldovaPost::class,
        'albania-post' => AlbaniaPost::class,
        'belarus-post' => BelarusPost::class,
        'macedonia-post' => MacedoniaPost::class,
        'bosnia-post' => BosniaPost::class,
        'deutsche-post' => DeutschePost::class,
        'montenegro-post' => MontenegroPost::class,
        'andorra-post' => AndorraPost::class,
        'monaco-post' => MonacoPost::class,
        'liechtenstein-post' => LiechtensteinPost::class,
        'san-marino-post' => SanMarinoPost::class,
        'vatican-post' => VaticanPost::class,
        'gibraltar-post' => GibraltarPost::class,
        'jersey-post' => JerseyPost::class,
        'guernsey-post' => GuernseyPost::class,
        'isle-of-man-post' => IsleOfManPost::class,
        'faroe-post' => FaroePost::class,
        'greenland-post' => GreenlandPost::class,
        'aland-post' => AlandPost::class,
        'colombia-post' => ColombiaPost::class,
        'peru-post' => PeruPost::class,
        'uruguay-post' => UruguayPost::class,
        'paraguay-post' => ParaguayPost::class,
        'bolivia-post' => BoliviaPost::class,
        'ecuador-post' => EcuadorPost::class,
        'venezuela-post' => VenezuelaPost::class,
        'costa-rica-post' => CostaRicaPost::class,
        'panama-post' => PanamaPost::class,
        'dominican-post' => DominicanPost::class,
        'guatemala-post' => GuatemalaPost::class,
        'honduras-post' => HondurasPost::class,
        'el-salvador-post' => ElSalvadorPost::class,
        'nicaragua-post' => NicaraguaPost::class,
        'cuba-post' => CubaPost::class,
        'jamaica-post' => JamaicaPost::class,
        'trinidad-post' => TrinidadPost::class,
        'barbados-post' => BarbadosPost::class,
        'bahamas-post' => BahamasPost::class,
        'suriname-post' => SurinamePost::class,
        'guyana-post' => GuyanaPost::class,
        'morocco-post' => MoroccoPost::class,
        'algeria-post' => AlgeriaPost::class,
        'tunisia-post' => TunisiaPost::class,
        'kenya-post' => KenyaPost::class,
        'nigeria-post' => NigeriaPost::class,
        'ethiopia-post' => EthiopiaPost::class,
        'ghana-post' => GhanaPost::class,
        'tanzania-post' => TanzaniaPost::class,
        'uganda-post' => UgandaPost::class,
        'rwanda-post' => RwandaPost::class,
        'zambia-post' => ZambiaPost::class,
        'zimbabwe-post' => ZimbabwePost::class,
        'mozambique-post' => MozambiquePost::class,
        'angola-post' => AngolaPost::class,
        'senegal-post' => SenegalPost::class,
        'ivory-coast-post' => IvoryCoastPost::class,
        'cameroon-post' => CameroonPost::class,
        'mauritius-post' => MauritiusPost::class,
        'qatar-post' => QatarPost::class,
        'kuwait-post' => KuwaitPost::class,
        'bahrain-post' => BahrainPost::class,
        'bangladesh-post' => BangladeshPost::class,
        'nepal-post' => NepalPost::class,
        'sri-lanka-post' => SriLankaPost::class,
        'myanmar-post' => MyanmarPost::class,
        'cambodia-post' => CambodiaPost::class,
        'laos-post' => LaosPost::class,
        'mongolia-post' => MongoliaPost::class,
        'georgia-post' => GeorgiaPost::class,
        'azerbaijan-post' => AzerbaijanPost::class,
        'armenia-post' => ArmeniaPost::class,
        'uzbekistan-post' => UzbekistanPost::class,
        'kyrgyzstan-post' => KyrgyzstanPost::class,
        'tajikistan-post' => TajikistanPost::class,
        'turkmenistan-post' => TurkmenistanPost::class,
        'afghanistan-post' => AfghanistanPost::class,
        'bhutan-post' => BhutanPost::class,
        'maldives-post' => MaldivesPost::class,
        'brunei-post' => BruneiPost::class,
        'papua-post' => PapuaPost::class,
        'fiji-post' => FijiPost::class,
        'samoa-post' => SamoaPost::class,
    ],
];
