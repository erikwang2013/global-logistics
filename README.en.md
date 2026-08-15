# global-logistics

A unified-facade composer package for domestic (China) express and international logistics tracking (PHP 8.2+, PSR-4, framework-agnostic).

[简体中文](README.md)

## About

**global-logistics** converges tracking for **209** express / postal carriers worldwide into a single facade: pass in a tracking number, and the channel (domestic / international) and carrier are detected automatically — no need to deal with per-carrier protocol differences (signatures, OAuth2, XML/JSON, status mapping).

| Metric | Value |
|---|---|
| Integrated carriers | **209** (45 domestic + 164 international) |
| Tracking-number detection rules | 187 (order-sensitive, first match wins) |
| International coverage | Big four express (DHL / FedEx / UPS / USPS) + national postal S10 systems (Europe, Latin America & Caribbean, Africa & Middle East, Asia-Pacific) |
| Unified status semantics | `TrackStatus` with 7 states (incl. exception / returned) |
| Tests | 1663 test cases / 6662 assertions, all green |
| Requirements | PHP 8.2+, PSR-4 / PSR-18, framework-agnostic; drop-in for Laravel / ThinkPHP / Hyperf / Webman / Yii 2 |

## Overview

Built for e-commerce, warehousing, and ERP systems, it converges the official APIs of domestic express and international logistics into a single facade:

- **One entry point**: `Logistics::track($trackingNo)` auto-detects the channel and carrier — no need to know which carrier a number belongs to
- **One data model**: every carrier returns the same `Tracking` / `TrackingEvent` structure; the business layer deals with a single shape
- **One status semantic**: carriers' varied raw statuses map to a unified `TrackStatus` enum (7 states)
- **Global coverage**: 164 international carriers, incl. DHL / FedEx / UPS / USPS and national postal S10 systems (Europe, Latin America & Caribbean, Africa & Middle East, Asia-Pacific)
- **Zero hardcoded credentials**: all keys are injected via configuration; code and secrets are fully separated

## Features

### Integrated carriers (209)

| Channel | Carrier | Code | Tracking | Order / Label / Subscribe |
|---|---|---|---|---|
| Domestic | SF Express | `sf` | ✅ | Being integrated as carriers open up their APIs |
| Domestic | ZTO Express | `zto` | ✅ | Same as above |
| Domestic | YTO Express | `yto` | ✅ | Same as above |
| Domestic | J&T Express | `jt` | ✅ | Same as above |
| Domestic | Yunda Express | `yd` | ✅ | Same as above |
| Domestic | STO Express | `sto` | ✅ | Same as above |
| Domestic | JD Logistics | `jd` | ✅ | Same as above |
| Domestic | EMS | `ems` | ✅ | Same as above |
| Domestic | Best Express | `ht` | ✅ | Same as above |
| Domestic | Deppon | `debon` | ✅ | Same as above |
| Domestic | KuaYue Express | `ky` | ✅ | Same as above |
| Domestic | Aneng Logistics | `ane` | ✅ | Same as above |
| Domestic | Cainiao Express | `cainiao` | ✅ | Same as above |
| Domestic | China Post | `china-post` | ✅ | Same as above |
| Domestic | Suning Logistics | `suning` | ✅ | Same as above |
| Domestic | UC Express | `uc` | ✅ | Same as above |
| Domestic | Yimidida | `ymd` | ✅ | Same as above |
| Domestic | ZJS Express | `zjs` | ✅ | Same as above |
| Domestic | Tiantian Express | `tiantian` | ✅ | Same as above |
| Domestic | ZTO Freight | `zto-freight` | ✅ | Same as above |
| Domestic | Dainiao (Cainiao Direct) | `dainiao` | ✅ | Same as above |
| Domestic | CRE Express | `cre` | ✅ | Same as above |
| Domestic | Shunxin Jieda | `sxjd` | ✅ | Same as above |
| Domestic | Sure Express | `sure` | ✅ | Same as above |
| Domestic | Xinfeng Logistics | `xf` | ✅ | Same as above |
| Domestic | Lianhaotong | `lht` | ✅ | Same as above |
| Domestic | RRS Logistics | `rrs` | ✅ | Same as above |
| Domestic | Fengwang Express | `fengwang` | ✅ | Same as above |
| Domestic | Best Freight | `ht-freight` | ✅ | Same as above |
| Domestic | Yunda Freight | `yd-freight` | ✅ | Same as above |
| Domestic | YTO Freight | `yto-freight` | ✅ | Same as above |
| Domestic | Zengyi Express | `zy` | ✅ | Same as above |
| Domestic | CAE Express | `cae` | ✅ | Same as above |
| Domestic | Tiandi Huayu | `huayu` | ✅ | Same as above |
| Domestic | Jiaji Freight | `jiaji` | ✅ | Same as above |
| Domestic | Longbang Express | `longbang` | ✅ | Same as above |
| Domestic | Quanyi Express | `qy` | ✅ | Same as above |
| Domestic | Suteng Logistics | `suteng` | ✅ | Same as above |
| Domestic | Zhongtie Logistics | `zhongtie` | ✅ | Same as above |
| Domestic | Zhongyou Logistics | `zhongyou` | ✅ | Same as above |
| Domestic | Zengyi Express | `zengyi` | ✅ | Same as above |
| Domestic | Quanfeng Express | `quanfeng` | ✅ | Same as above |
| Domestic | Guotong Express | `guotong` | ✅ | Same as above |
| Domestic | Yuancheng Freight | `yuancheng` | ✅ | Same as above |
| Domestic | Xinbang Logistics | `xinbang` | ✅ | Same as above |
| International | DHL | `dhl` | ✅ (OAuth2) | Same as above |
| International | FedEx | `fedex` | ✅ (OAuth2) | Same as above |
| International | UPS | `ups` | ✅ (OAuth2) | Same as above |
| International | USPS | `usps` | ✅ | Same as above |
| International | Royal Mail | `royal-mail` | ✅ (OAuth2) | Same as above |
| International | Canada Post | `canada-post` | ✅ | Same as above |
| International | Australia Post | `australia-post` | ✅ | Same as above |
| International | Japan Post | `japan-post` | ✅ (no auth) | Same as above |
| International | Aramex | `aramex` | ✅ | Same as above |
| International | GLS | `gls` | ✅ | Same as above |
| International | DPD | `dpd` | ✅ | Same as above |
| International | PostNL | `postnl` | ✅ | Same as above |
| International | Cainiao International | `cainiao-intl` | ✅ | Same as above |
| International | Correios (Brazil) | `correios` | ✅ | Same as above |
| International | Evri | `evri` | ✅ | Same as above |
| International | 4PX | `fourpx` | ✅ | Same as above |
| International | Hong Kong Post | `hong-kong-post` | ✅ | Same as above |
| International | Kerry Express | `kerry` | ✅ | Same as above |
| International | Korea Post | `korea-post` | ✅ | Same as above |
| International | La Poste (France) | `la-poste` | ✅ | Same as above |
| International | NZ Post | `nz-post` | ✅ | Same as above |
| International | Poste Italiane | `poste-italiane` | ✅ | Same as above |
| International | Russia Post | `russia-post` | ✅ | Same as above |
| International | Singapore Post | `singapore-post` | ✅ | Same as above |
| International | Swiss Post | `swiss-post` | ✅ (OAuth2) | Same as above |
| International | Yodel | `yodel` | ✅ (OAuth2) | Same as above |
| International | YunExpress | `yunexpress` | ✅ | Same as above |
| International | Yanwen | `yanwen` | ✅ | Same as above |
| International | SF International | `sf-international` | ✅ | Same as above |
| International | TNT | `tnt` | ✅ | Same as above |
| International | OnTrac | `ontrac` | ✅ | Same as above |
| International | Purolator | `purolator` | ✅ | Same as above |
| International | bpost (Belgium) | `bpost` | ✅ | Same as above |
| International | Correos (Spain) | `correos` | ✅ | Same as above |
| International | Delhivery (India) | `delhivery` | ✅ | Same as above |
| International | InPost (Poland parcel lockers) | `inpost` | ✅ | Same as above |
| International | Omniva (Estonia) | `omniva` | ✅ | Same as above |
| International | Posti (Finland) | `posti` | ✅ | Same as above |
| International | Bring (Norway) | `bring` | ✅ | Same as above |
| International | Austrian Post | `austrian-post` | ✅ | Same as above |
| International | Thailand Post | `thailand-post` | ✅ | Same as above |
| International | Chunghwa Post (Taiwan) | `chunghwa-post` | ✅ | Same as above |
| International | PostNord (Sweden/Denmark) | `postnord` | ✅ | Same as above |
| International | CTT (Portugal) | `ctt` | ✅ | Same as above |
| International | An Post (Ireland) | `an-post` | ✅ | Same as above |
| International | Poczta Polska (Poland) | `poczta-polska` | ✅ | Same as above |
| International | India Post | `india-post` | ✅ | Same as above |
| International | Pos Malaysia | `pos-malaysia` | ✅ | Same as above |
| International | Emirates Post (UAE) | `emirates-post` | ✅ | Same as above |
| International | Magyar Posta (Hungary) | `magyar-posta` | ✅ | Same as above |
| International | Česká pošta (Czech Republic) | `ceska-posta` | ✅ | Same as above |
| International | ELTA (Greece) | `elta` | ✅ | Same as above |
| International | Viettel Post (Vietnam) | `viettel-post` | ✅ | Same as above |
| International | ZTO International | `zto-intl` | ✅ | Same as above |
| International | YTO International | `yto-intl` | ✅ | Same as above |
| International | J&T International | `jt-intl` | ✅ | Same as above |
| International | Winit | `winit` | ✅ | Same as above |
| International | Ukrposhta (Ukraine) | `ukrposhta` | ✅ | Same as above |
| International | Turkey PTT | `turkey-post` | ✅ | Same as above |
| International | Israel Post | `israel-post` | ✅ | Same as above |
| International | Egypt Post | `egypt-post` | ✅ | Same as above |
| International | Saudi Post | `saudi-post` | ✅ | Same as above |
| International | South African Post | `south-african-post` | ✅ | Same as above |
| International | Correos de México (Mexico) | `correos-mexico` | ✅ | Same as above |
| International | Correo Argentino (Argentina) | `correo-argentino` | ✅ | Same as above |
| International | Correos de Chile (Chile) | `correos-chile` | ✅ | Same as above |
| International | Pos Indonesia | `pos-indonesia` | ✅ | Same as above |
| International | PHLPost (Philippines) | `phl-post` | ✅ | Same as above |
| International | Pakistan Post | `pakistan-post` | ✅ | Same as above |
| International | Kazpost (Kazakhstan) | `kazpost` | ✅ | Same as above |
| International | Poșta Română (Romania) | `romania-post` | ✅ | Same as above |
| International | Hrvatska pošta (Croatia) | `croatia-post` | ✅ | Same as above |
| International | Slovak Post | `slovak-post` | ✅ | Same as above |
| International | Pošta Slovenije (Slovenia) | `slovenia-post` | ✅ | Same as above |
| International | Pošta Srbije (Serbia) | `serbia-post` | ✅ | Same as above |
| International | Bulgarian Posts | `bulgaria-post` | ✅ | Same as above |
| International | Lietuvos paštas (Lithuania) | `lithuania-post` | ✅ | Same as above |
| International | Latvijas Pasts (Latvia) | `latvia-post` | ✅ | Same as above |
| International | Íslandspóstur (Iceland) | `iceland-post` | ✅ | Same as above |
| International | MaltaPost | `malta-post` | ✅ | Same as above |
| International | POST Luxembourg | `luxembourg-post` | ✅ | Same as above |
| International | Cyprus Post | `cyprus-post` | ✅ | Same as above |
| International | Poșta Moldovei (Moldova) | `moldova-post` | ✅ | Same as above |
| International | Posta Shqiptare (Albania) | `albania-post` | ✅ | Same as above |
| International | Belpochta (Belarus) | `belarus-post` | ✅ | Same as above |
| International | Makedonska Pošta (North Macedonia) | `macedonia-post` | ✅ | Same as above |
| International | BH Pošta (Bosnia and Herzegovina) | `bosnia-post` | ✅ | Same as above |
| International | Deutsche Post (Germany) | `deutsche-post` | ✅ | Same as above |
| International | Montenegro Post | `montenegro-post` | ✅ | Same as above |
| International | Andorra Post | `andorra-post` | ✅ | Same as above |
| International | La Poste Monaco | `monaco-post` | ✅ | Same as above |
| International | Liechtenstein Post | `liechtenstein-post` | ✅ | Same as above |
| International | Poste San Marino | `san-marino-post` | ✅ | Same as above |
| International | Poste Vaticane (Vatican) | `vatican-post` | ✅ | Same as above |
| International | Royal Gibraltar Post | `gibraltar-post` | ✅ | Same as above |
| International | Jersey Post | `jersey-post` | ✅ | Same as above |
| International | Guernsey Post | `guernsey-post` | ✅ | Same as above |
| International | Isle of Man Post | `isle-of-man-post` | ✅ | Same as above |
| International | Posta Faroe Islands | `faroe-post` | ✅ | Same as above |
| International | Post Greenland | `greenland-post` | ✅ | Same as above |
| International | Post Åland | `aland-post` | ✅ | Same as above |
| International | 4-72 (Colombia) | `colombia-post` | ✅ | Same as above |
| International | Serpost (Peru) | `peru-post` | ✅ | Same as above |
| International | Correo Uruguayo (Uruguay) | `uruguay-post` | ✅ | Same as above |
| International | Correo Paraguayo (Paraguay) | `paraguay-post` | ✅ | Same as above |
| International | Correos de Bolivia (Bolivia) | `bolivia-post` | ✅ | Same as above |
| International | Correos del Ecuador (Ecuador) | `ecuador-post` | ✅ | Same as above |
| International | Ipostel (Venezuela) | `venezuela-post` | ✅ | Same as above |
| International | Correos de Costa Rica (Costa Rica) | `costa-rica-post` | ✅ | Same as above |
| International | Correos de Panamá (Panama) | `panama-post` | ✅ | Same as above |
| International | INPOSDOM (Dominican Republic) | `dominican-post` | ✅ | Same as above |
| International | Correo de Guatemala (Guatemala) | `guatemala-post` | ✅ | Same as above |
| International | HonduCorreo (Honduras) | `honduras-post` | ✅ | Same as above |
| International | Correos de El Salvador (El Salvador) | `el-salvador-post` | ✅ | Same as above |
| International | Correos de Nicaragua (Nicaragua) | `nicaragua-post` | ✅ | Same as above |
| International | Correos de Cuba (Cuba) | `cuba-post` | ✅ | Same as above |
| International | Jamaica Post | `jamaica-post` | ✅ | Same as above |
| International | TTPOST (Trinidad and Tobago) | `trinidad-post` | ✅ | Same as above |
| International | Barbados Post | `barbados-post` | ✅ | Same as above |
| International | Bahamas Post | `bahamas-post` | ✅ | Same as above |
| International | Suriname Post | `suriname-post` | ✅ | Same as above |
| International | Guyana Post | `guyana-post` | ✅ | Same as above |
| International | Barid Al-Maghrib (Morocco) | `morocco-post` | ✅ | Same as above |
| International | Algérie Poste (Algeria) | `algeria-post` | ✅ | Same as above |
| International | La Poste Tunisienne (Tunisia) | `tunisia-post` | ✅ | Same as above |
| International | Posta Kenya (Kenya) | `kenya-post` | ✅ | Same as above |
| International | NIPOST (Nigeria) | `nigeria-post` | ✅ | Same as above |
| International | Ethiopia Post | `ethiopia-post` | ✅ | Same as above |
| International | Ghana Post | `ghana-post` | ✅ | Same as above |
| International | Tanzania Post | `tanzania-post` | ✅ | Same as above |
| International | Uganda Post | `uganda-post` | ✅ | Same as above |
| International | Rwanda Post | `rwanda-post` | ✅ | Same as above |
| International | Zampost (Zambia) | `zambia-post` | ✅ | Same as above |
| International | Zimpost (Zimbabwe) | `zimbabwe-post` | ✅ | Same as above |
| International | Mozambique Post | `mozambique-post` | ✅ | Same as above |
| International | Correios de Angola (Angola) | `angola-post` | ✅ | Same as above |
| International | La Poste Sénégalaise (Senegal) | `senegal-post` | ✅ | Same as above |
| International | La Poste de Côte d'Ivoire (Ivory Coast) | `ivory-coast-post` | ✅ | Same as above |
| International | Cameroon Post | `cameroon-post` | ✅ | Same as above |
| International | Mauritius Post | `mauritius-post` | ✅ | Same as above |
| International | Qatar Post | `qatar-post` | ✅ | Same as above |
| International | Kuwait Post | `kuwait-post` | ✅ | Same as above |
| International | Bahrain Post | `bahrain-post` | ✅ | Same as above |
| International | Bangladesh Post | `bangladesh-post` | ✅ | Same as above |
| International | Nepal Post | `nepal-post` | ✅ | Same as above |
| International | Sri Lanka Post | `sri-lanka-post` | ✅ | Same as above |
| International | Myanmar Post | `myanmar-post` | ✅ | Same as above |
| International | Cambodia Post | `cambodia-post` | ✅ | Same as above |
| International | Laos Post | `laos-post` | ✅ | Same as above |
| International | Mongolia Post | `mongolia-post` | ✅ | Same as above |
| International | Georgian Post | `georgia-post` | ✅ | Same as above |
| International | Azərpoçt (Azerbaijan) | `azerbaijan-post` | ✅ | Same as above |
| International | HayPost (Armenia) | `armenia-post` | ✅ | Same as above |
| International | Uzbekistan Post | `uzbekistan-post` | ✅ | Same as above |
| International | Kyrgyz Post (Kyrgyzstan) | `kyrgyzstan-post` | ✅ | Same as above |
| International | Tajikistan Post | `tajikistan-post` | ✅ | Same as above |
| International | Turkmenistan Post | `turkmenistan-post` | ✅ | Same as above |
| International | Afghanistan Post | `afghanistan-post` | ✅ | Same as above |
| International | Bhutan Post | `bhutan-post` | ✅ | Same as above |
| International | Maldives Post | `maldives-post` | ✅ | Same as above |
| International | Brunei Post | `brunei-post` | ✅ | Same as above |
| International | Papua New Guinea Post | `papua-post` | ✅ | Same as above |
| International | Fiji Post | `fiji-post` | ✅ | Same as above |
| International | Samoa Post | `samoa-post` | ✅ | Same as above |

### Unified status enum (`GlobalLogistics\Support\TrackStatus`)

`PENDING` → `IN_TRANSIT` → `OUT_FOR_DELIVERY` → `DELIVERED`; anomalies map to `EXCEPTION`, returns to `RETURNED`, unrecognized statuses to `UNKNOWN`.

### Core capabilities

- Auto-detection of tracking numbers (187 regex rules, order-sensitive, domestic rules take priority)
- Unified tracking (`Logistics::track()`) and explicit channel calls (`domestic()` / `international()`)
- Unified exception hierarchy (auth failure / tracking not found / network error / carrier not registered / API error)
- HTTP infrastructure: PSR-18 client, OAuth2 token auto-fetch & caching, automatic retry on failure
- Framework auto-discovery: Laravel / ThinkPHP 8 / Hyperf / Webman / Yii 2, drop-in ready
- Callback signature verification (SF Express example: `verifyCallbackSignature()`)

## Usage

### Installation

```bash
composer require erikwang2013/global-logistics
```

### Configuration

```php
<?php

use GlobalLogistics\Logistics;

Logistics::configure([
    // Domestic
    'sf' => ['partner_id' => '...', 'checkword' => '...'],
    'zto' => ['company_id' => '...', 'secret' => '...'],
    'yto' => ['app_key' => '...', 'app_secret' => '...'],
    'jt' => ['api_key' => '...', 'secret' => '...'],
    'yd' => ['app_key' => '...', 'app_secret' => '...'],
    'sto' => [],
    'jd' => [],
    'ems' => ['app_id' => '...'],
    'ht' => ['partner_id' => '...', 'token' => '...'],
    'debon' => ['app_key' => '...', 'app_secret' => '...'],
    'ky' => ['app_key' => '...', 'app_secret' => '...'],
    'ane' => ['app_key' => '...'],
    // International
    'dhl' => ['client_id' => '...', 'client_secret' => '...'],
    'fedex' => ['client_id' => '...', 'client_secret' => '...'],
    'ups' => ['client_id' => '...', 'client_secret' => '...'],
    'usps' => ['user_id' => '...'],
    'royal-mail' => ['client_id' => '...', 'client_secret' => '...'],
    'canada-post' => ['customer_number' => '...', 'api_key' => '...'],
    'australia-post' => ['api_key' => '...'],
    'japan-post' => [],
    'aramex' => ['user_name' => '...', 'password' => '...', 'account_number' => '...'],
    'gls' => ['api_key' => '...'],
    'dpd' => ['user_name' => '...', 'password' => '...'],
    'postnl' => ['api_key' => '...'],

    // Optional: custom PSR-18 HTTP client (a Guzzle client is built automatically by default)
    'http_client' => null,
    // Optional: retry count on failure (default 2)
    'max_retries' => 2,
]);
```

> Framework projects (Laravel, etc.) can use the `config/logistics.php` template directly — see "Framework Integration".

### Tracking

```php
// Auto-detect the channel (domestic/international) and carrier
$tracking = Logistics::track('SF1234567890');

// Explicit carrier (when number rules cannot determine it)
$tracking = Logistics::domestic('sf')->queryTrack('SF1234567890');
$tracking = Logistics::international('dhl')->queryTrack('DHL1234567890');

echo $tracking->status->name;       // DELIVERED
echo $tracking->latestDescription;  // Package delivered
echo $tracking->carrierCode;        // sf
echo $tracking->deliveredAt?->format('Y-m-d H:i:s');  // delivery time (when delivered)

foreach ($tracking->events as $event) {
    echo $event->occurredAt?->format('Y-m-d H:i:s'), ' ', $event->location, ' ', $event->description, PHP_EOL;
}
```

### Error handling

All exceptions extend `GlobalLogistics\Exceptions\LogisticsException` and can be caught uniformly:

| Exception | Scenario |
|---|---|
| `CarrierNotFoundException` | Tracking number could not be matched to a carrier |
| `TrackingNotFoundException` | Valid number but the carrier has no tracking record |
| `AuthException` | Authentication failed (wrong credentials, etc.) |
| `NetworkException` | HTTP network error (implements PSR-18 `NetworkExceptionInterface`) |
| `LogisticsException` | Other API / parsing errors |

```php
use GlobalLogistics\Exceptions\LogisticsException;

try {
    $tracking = Logistics::track('SF1234567890');
} catch (LogisticsException $e) {
    // Log $e->getMessage(), which contains the carrier code and the original
    // error code, e.g. "[SF A1001] Required parameter cannot be empty"
}
```

### Callback signature verification (subscription push)

Example callback handler for carriers that expose a subscribe API (using SF Express):

```php
use GlobalLogistics\Logistics;

$carrier = Logistics::domestic('sf');
if (!$carrier->verifyCallbackSignature((string) file_get_contents('php://input'), (string) $_SERVER['HTTP_DIGEST'])) {
    http_response_code(401);
    exit('signature mismatch');
}
// Signature verified, handle the tracking push…
```

## Architecture

![global-logistics architecture](docs/images/architecture.svg)

![global-logistics design sequence diagram](docs/images/design.svg)

### Directory structure

```
global-logistics/
├── src/
│   ├── Carriers/
│   │   ├── Domestic/          # 45 domestic adapters (SF Express, ZTO, YTO, …)
│   │   └── International/     # 164 international adapters (DHL, FedEx, UPS, national postal S10, …)
│   ├── Exceptions/            # exception hierarchy (LogisticsException + 4 scenario exceptions)
│   ├── Framework/             # framework auto-discovery (Laravel / ThinkPHP / Hyperf / Webman / Yii 2)
│   ├── Http/                  # PSR-18: OAuthTokenClient, RetryingClient, HttpClientFactory
│   ├── Models/                # Tracking / TrackingEvent / Order / OrderRequest / Label
│   ├── Resources/             # carrier-registry.php (209 carriers), detector-rules.php (187 rules)
│   ├── Support/               # TrackStatus and other support classes
│   ├── CarrierFactory.php     # registry → adapter instantiation
│   ├── CarrierInterface.php   # unified adapter contract
│   ├── Channel.php            # domestic / international channel enum
│   ├── Config.php             # dot-notation config access
│   ├── Detection.php          # detection result
│   ├── Detector.php           # tracking-number rule detection
│   ├── Install.php            # installation bootstrap
│   └── Logistics.php          # static facade
├── config/
│   └── logistics.php          # config template (credential placeholders for 209 carriers)
├── docs/
│   ├── images/                # architecture / design sequence diagrams
│   └── superpowers/           # design specs and implementation plans
├── tests/
│   ├── Carriers/              # 7 test cases per carrier (539 total)
│   ├── Unit/                  # detector, registry smoke tests, etc.
│   └── fixtures/              # track / empty / error fixtures per carrier
├── composer.json
├── README.md
└── README.en.md
```

### Layer responsibilities

- **`Logistics`** (`src/Logistics.php`): static facade holding the global config, detector and factory; auto-initializes with an empty config when unconfigured
- **`Detector`** (`src/Detector.php` + `src/Resources/detector-rules.php`): regex rule table, first match wins, returns `Detection` (channel + carrier code); rules are order-sensitive (e.g. STO numbers starting with 77 must precede the generic 13-digit rule)
- **`CarrierFactory`** (`src/CarrierFactory.php` + `src/Resources/carrier-registry.php`): instantiates adapters from the "channel → code → adapter class" registry, uniformly injecting `Config` and the HTTP client
- **Carrier adapters** (`src/Carriers/`): implement `CarrierInterface` and handle per-carrier protocol differences (signatures, OAuth2, XML/JSON, status mapping); share a common template structure (`ENDPOINT` constant + `STATUS_MAP` + `mapEvent()`) so new carriers can be added from a template
- **HTTP layer** (`src/Http/`): `OAuthTokenClient` is a PSR-18 decorator that lazily fetches tokens, caches them in-process (expiring 60s early) and refreshes once on 401; `RetryingClient` retries failed requests per `max_retries`
- **Model layer** (`src/Models/`): `Tracking` / `TrackingEvent` are immutable; `Order` / `OrderRequest` / `Label` reserve the ordering / label capabilities
- **`Config`** (`src/Config.php`): dot-notation access (`$config->get('dhl.client_id')`)
- **Exceptions** (`src/Exceptions/`): `LogisticsException` is the base class, with 4 scenario exceptions

### Adding a new carrier

1. Create an adapter class (follow the `src/Carriers/Domestic/Yto.php` template): implement `CarrierInterface`, do the status mapping in `mapEvent()`
2. Registry: add "channel → code → class" to `src/Resources/carrier-registry.php`
3. Number rules: add a regex to `src/Resources/detector-rules.php` (mind order sensitivity — domestic rules first)
4. Add fixtures and adapter tests (mock HTTP, no real credentials needed)

## Framework Integration

After `composer require erikwang2013/global-logistics`, auto-discovery works per framework without manual registration; the config template uses carrier codes as top-level keys (see `config/logistics.php`, same structure as the `Logistics::configure()` argument).

### Laravel

- Auto-registration: composer `extra.laravel.providers` package discovery, no configuration needed
- Publish config: `php artisan vendor:publish --tag=global-logistics` (generates `config/logistics.php`)
- Usage: `\GlobalLogistics\Logistics::track('SF1234567890')`

### ThinkPHP 8

- Auto-registration: composer `extra.think.services` (generates `vendor/services.php` on install; `php think service:discover` regenerates it)
- Config: the app's `config/logistics.php` returns a same-structure array (overrides package defaults)
- Usage: `\GlobalLogistics\Logistics::track('SF1234567890')`

### Hyperf

- Auto-discovery: composer `extra.hyperf.config` points to the ConfigProvider
- Publish config: `php bin/hyperf.php vendor:publish` (publishes to `config/autoload/logistics.php`)
- Usage: `\GlobalLogistics\Logistics::track('SF1234567890')`

### Webman

- Auto-install: webman project templates ship composer hooks like `post-package-install`; on install/update the config is copied to `config/plugin/erikwang2013/global-logistics/`, and removed on uninstall
- Read config: `config('plugin.erikwang2013.global-logistics.app.sf.partner_id')`
- Usage: `\GlobalLogistics\Logistics::track('SF1234567890')`

### Yii 2

- Auto-registration: package type `yii2-extension` + composer `extra.bootstrap`, runs on every app bootstrap
- Config: add `'logistics' => [...same-structure array...]` to the app config `params`
- Usage: `\GlobalLogistics\Logistics::track('SF1234567890')`
- If the package entry is missing from `vendor/yiisoft/extensions.php` (rare), run `composer dump-autoload` to rebuild

## Development

```bash
composer install
composer test
```

The full test suite runs without real credentials (adapter tests use mock HTTP + fixtures; framework integration tests use real framework classes).
