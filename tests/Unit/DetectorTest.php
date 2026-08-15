<?php

declare(strict_types=1);

namespace GlobalLogistics\Tests\Unit;

use GlobalLogistics\Channel;
use GlobalLogistics\Detector;
use GlobalLogistics\Exceptions\CarrierNotFoundException;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase
{
    public function testDetectsSfDomestic(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('SF1234567890');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('sf', $result->carrierCode);
    }

    public function testDetectsDhlInternational(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('DHL1234567890');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('dhl', $result->carrierCode);
    }

    public function testUnknownThrows(): void
    {
        $detector = Detector::withDefaults();

        $this->expectException(CarrierNotFoundException::class);
        $detector->detect('ZZZ99999999');
    }

    public function testCustomRules(): void
    {
        $detector = new Detector([
            '/^AB\d{8}$/' => ['domestic', 'ab'],
        ]);
        $result = $detector->detect('AB12345678');

        $this->assertSame('ab', $result->carrierCode);
    }

    public function testDetectsYunda(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('YD12345678');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('yd', $result->carrierCode);
    }

    public function testDetectsShentongBeforeZtoRule(): void
    {
        // 77 开头 13 位数字：/^77\d{11}$/ 必须先于 /^\d{13}$/ 命中，判定为 sto 而非 zto
        $detector = Detector::withDefaults();
        $result = $detector->detect('7730012345678');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('sto', $result->carrierCode);
    }

    public function testDetectsJd(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('JD1234567890');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('jd', $result->carrierCode);
    }

    public function testDetectsEmsBeforeGenericFedExRule(): void
    {
        // EA...CN 同时匹配通用 FedEx 规则（/^[A-Z]{2}\d{9}[A-Z]{2}$/i），EMS 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('EA123456789CN');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('ems', $result->carrierCode);
    }

    public function testDetectsFedExPrefix(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('FEDEX1234567890');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('fedex', $result->carrierCode);
    }

    public function testDetectsUps(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('1Z9999999999999999');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ups', $result->carrierCode);
    }

    public function testDetectsUsps(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('9400111899223197448523');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('usps', $result->carrierCode);
    }

    public function testDetectsTenDigitDhl(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('1234567890');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('dhl', $result->carrierCode);
    }

    public function testTenDigitSeventySevenPrefixUnknown(): void
    {
        // 77 开头 10 位纯数字为国内旧式申通单号，不得误判为 DHL 国际
        $detector = Detector::withDefaults();

        $this->expectException(CarrierNotFoundException::class);
        $detector->detect('7734567890');
    }

    public function testDetectsHt(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('HT123456789012');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('ht', $result->carrierCode);
    }

    public function testDetectsDebonDpk(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('DPK12345678');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('debon', $result->carrierCode);
    }

    public function testDetectsDebonDb(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('DB1234567890');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('debon', $result->carrierCode);
    }

    public function testDetectsKy(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('KY1234567890');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('ky', $result->carrierCode);
    }

    public function testDetectsAne(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('ANE12345678');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('ane', $result->carrierCode);
    }

    public function testDetectsRoyalMailBeforeGenericFedExRule(): void
    {
        // XX...GB 同时匹配通用 FedEx 规则（/^[A-Z]{2}\d{9}[A-Z]{2}$/i），royal-mail 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('AB123456789GB');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('royal-mail', $result->carrierCode);
    }

    public function testDetectsJapanPostBeforeGenericFedExRule(): void
    {
        // XX...JP 同时匹配通用 FedEx 规则，japan-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('AB123456789JP');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('japan-post', $result->carrierCode);
    }

    public function testDetectsAustraliaPost(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('AUP12345678');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('australia-post', $result->carrierCode);
    }

    public function testDetectsPostnl(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('3SAB12345678901');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('postnl', $result->carrierCode);
    }

    public function testDetectsCanadaPost(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('1234567890123456');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('canada-post', $result->carrierCode);
    }

    public function testDetectsCainiao(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('CA123456789');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('cainiao', $result->carrierCode);
    }

    public function testDetectsChinaPostBeforeGenericFedExRule(): void
    {
        // RA...CN 同时匹配通用 FedEx 规则，china-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CN');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('china-post', $result->carrierCode);
    }

    public function testDetectsSuning(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('62000000000000000001');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('suning', $result->carrierCode);
    }

    public function testDetectsUc(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('900752733683');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('uc', $result->carrierCode);
    }

    public function testDetectsYmd(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('YMD123456789');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('ymd', $result->carrierCode);
    }

    public function testDetectsZjsBeforeZtoRule(): void
    {
        // 3 开头 13 位同时匹配 /^\d{13}$/（zto），zjs 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('3703743553612');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('zjs', $result->carrierCode);
    }

    public function testDetectsCainiaoIntl(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('LP00123456789012');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('cainiao-intl', $result->carrierCode);
    }

    public function testDetectsCorreiosBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('DG049186226BR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('correios', $result->carrierCode);
    }

    public function testDetectsEvri(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('TV12345678901234');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('evri', $result->carrierCode);
    }

    public function testDetectsFourPx(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('4PX1234567890');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('fourpx', $result->carrierCode);
    }

    public function testDetectsHongKongPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RB123456789HK');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('hong-kong-post', $result->carrierCode);
    }

    public function testDetectsKerry(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('KK0123456789TH');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('kerry', $result->carrierCode);
    }

    public function testDetectsKoreaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RR123456789KR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('korea-post', $result->carrierCode);
    }

    public function testDetectsLaPosteBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('EY604176344FR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('la-poste', $result->carrierCode);
    }

    public function testDetectsNzPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RR123456789NZ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('nz-post', $result->carrierCode);
    }

    public function testDetectsPosteItalianeBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789IT');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('poste-italiane', $result->carrierCode);
    }

    public function testDetectsRussiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789RU');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('russia-post', $result->carrierCode);
    }

    public function testDetectsSingaporePostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RR123456789SG');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('singapore-post', $result->carrierCode);
    }

    public function testDetectsSwissPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('XX123456789CH');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('swiss-post', $result->carrierCode);
    }

    public function testDetectsYodelBeforeJdRule(): void
    {
        // JD0 开头 16 位数字同时匹配京东规则（/^JD[A-Z0-9]{8,18}$/i），yodel 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('JD0001234567890123');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('yodel', $result->carrierCode);
    }

    public function testDetectsYunexpressAfterYtoRule(): void
    {
        // YT+13 位与 yto（YT+10-12 位）位数互斥，无顺序冲突
        $detector = Detector::withDefaults();
        $result = $detector->detect('YT1234567890123');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('yunexpress', $result->carrierCode);
    }

    public function testDetectsSfInternationalAfterDomesticSfRule(): void
    {
        // SF+13 位与国内顺丰（SF+10-12 位）位数互斥，顺序无冲突
        $detector = Detector::withDefaults();
        $result = $detector->detect('SF1234567890123');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('sf-international', $result->carrierCode);
    }

    public function testDetectsZtoFreightBeforePurolatorRule(): void
    {
        // 32 开头 12 位同时匹配 /^3\d{11}$/（purolator），国内中通快运规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('320000038967');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('zto-freight', $result->carrierCode);
    }

    public function testDetectsPurolator(): void
    {
        // 33 开头 12 位命中 purolator；32 开头已被 zto-freight（国内优先）截获
        $detector = Detector::withDefaults();
        $result = $detector->detect('330112345678');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('purolator', $result->carrierCode);
    }

    public function testDetectsDainiaoBeforeUcRule(): void
    {
        // 6 开头 12 位同时匹配 /^\d{12}$/（uc），丹鸟规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('689012345678');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('dainiao', $result->carrierCode);
    }

    public function testDetectsTiantian(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('50301872145678');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('tiantian', $result->carrierCode);
    }

    public function testDetectsTnt(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('256867154');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('tnt', $result->carrierCode);
    }

    public function testDetectsSxjd(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('SJ1234567890');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('sxjd', $result->carrierCode);
    }

    public function testDetectsCre(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('K12345678901');

        $this->assertSame(Channel::Domestic, $result->channel);
        $this->assertSame('cre', $result->carrierCode);
    }

    public function testDetectsOntrac(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('C11031500001879');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ontrac', $result->carrierCode);
    }

    public function testDetectsBpostBeforeGenericFedExRule(): void
    {
        // XX...BE 同时匹配通用 FedEx 规则，bpost 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RB123456789BE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bpost', $result->carrierCode);
    }

    public function testDetectsCorreosBeforeGenericFedExRule(): void
    {
        // XX...ES 同时匹配通用 FedEx 规则，correos 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RR123456789ES');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('correos', $result->carrierCode);
    }

    public function testDetectsYanwenBeforeGenericFedExRule(): void
    {
        // UA...YP 同时匹配通用 FedEx 规则（/^[A-Z]{2}\d{9}[A-Z]{2}$/i），燕文规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('UA123456789YP');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('yanwen', $result->carrierCode);
    }

    public function testDetectsAustrianPostBeforeGenericFedExRule(): void
    {
        // XX...AT 同时匹配通用 FedEx 规则，austrian-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RD123456789AT');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('austrian-post', $result->carrierCode);
    }

    public function testDetectsBringBeforeGenericFedExRule(): void
    {
        // XX...NO 同时匹配通用 FedEx 规则，bring 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RB123456789NO');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bring', $result->carrierCode);
    }

    public function testDetectsThailandPostBeforeGenericFedExRule(): void
    {
        // XX...TH 同时匹配通用 FedEx 规则，thailand-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RR123456789TH');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('thailand-post', $result->carrierCode);
    }

    public function testDetectsChunghwaPostBeforeGenericFedExRule(): void
    {
        // XX...TW 同时匹配通用 FedEx 规则，chunghwa-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RL123456789TW');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('chunghwa-post', $result->carrierCode);
    }

    public function testDetectsOmnivaBeforeGenericFedExRule(): void
    {
        // XX...EE 同时匹配通用 FedEx 规则，omniva 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RV123456789EE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('omniva', $result->carrierCode);
    }

    public function testDetectsPostiBeforeGenericFedExRule(): void
    {
        // XX...FI 同时匹配通用 FedEx 规则，posti 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('LJ123456789FI');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('posti', $result->carrierCode);
    }

    public function testDetectsPostNordSeBeforeGenericFedExRule(): void
    {
        // XX...SE 同时匹配通用 FedEx 规则，postnord 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789SE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('postnord', $result->carrierCode);
    }

    public function testDetectsPostNordDkBeforeGenericFedExRule(): void
    {
        // XX...DK 同时匹配通用 FedEx 规则，postnord 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789DK');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('postnord', $result->carrierCode);
    }

    public function testDetectsCttBeforeGenericFedExRule(): void
    {
        // XX...PT 同时匹配通用 FedEx 规则，ctt 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789PT');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ctt', $result->carrierCode);
    }

    public function testDetectsAnPostBeforeGenericFedExRule(): void
    {
        // XX...IE 同时匹配通用 FedEx 规则，an-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789IE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('an-post', $result->carrierCode);
    }

    public function testDetectsPocztaPolskaBeforeGenericFedExRule(): void
    {
        // XX...PL 同时匹配通用 FedEx 规则，poczta-polska 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789PL');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('poczta-polska', $result->carrierCode);
    }

    public function testDetectsIndiaPostBeforeGenericFedExRule(): void
    {
        // XX...IN 同时匹配通用 FedEx 规则，india-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789IN');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('india-post', $result->carrierCode);
    }

    public function testDetectsPosMalaysiaBeforeGenericFedExRule(): void
    {
        // XX...MY 同时匹配通用 FedEx 规则，pos-malaysia 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MY');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('pos-malaysia', $result->carrierCode);
    }

    public function testDetectsEmiratesPostBeforeGenericFedExRule(): void
    {
        // XX...AE 同时匹配通用 FedEx 规则，emirates-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('emirates-post', $result->carrierCode);
    }

    public function testDetectsMagyarPostaBeforeGenericFedExRule(): void
    {
        // XX...HU 同时匹配通用 FedEx 规则，magyar-posta 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789HU');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('magyar-posta', $result->carrierCode);
    }

    public function testDetectsCeskaPostaBeforeGenericFedExRule(): void
    {
        // XX...CZ 同时匹配通用 FedEx 规则，ceska-posta 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CZ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ceska-posta', $result->carrierCode);
    }

    public function testDetectsEltaBeforeGenericFedExRule(): void
    {
        // XX...GR 同时匹配通用 FedEx 规则，elta 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789GR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('elta', $result->carrierCode);
    }

    public function testDetectsViettelPostBeforeGenericFedExRule(): void
    {
        // XX...VN 同时匹配通用 FedEx 规则，viettel-post 规则必须先命中
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789VN');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('viettel-post', $result->carrierCode);
    }

    public function testDetectsUkrposhtaBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789UA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ukrposhta', $result->carrierCode);
    }

    public function testDetectsTurkeyPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789TR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('turkey-post', $result->carrierCode);
    }

    public function testDetectsIsraelPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789IL');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('israel-post', $result->carrierCode);
    }

    public function testDetectsEgyptPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789EG');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('egypt-post', $result->carrierCode);
    }

    public function testDetectsSaudiPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789SA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('saudi-post', $result->carrierCode);
    }

    public function testDetectsSouthAfricanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789ZA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('south-african-post', $result->carrierCode);
    }

    public function testDetectsCorreosMexicoBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MX');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('correos-mexico', $result->carrierCode);
    }

    public function testDetectsCorreoArgentinoBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('correo-argentino', $result->carrierCode);
    }

    public function testDetectsCorreosChileBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CL');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('correos-chile', $result->carrierCode);
    }

    public function testDetectsPosIndonesiaBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789ID');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('pos-indonesia', $result->carrierCode);
    }

    public function testDetectsPhlPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789PH');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('phl-post', $result->carrierCode);
    }

    public function testDetectsPakistanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789PK');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('pakistan-post', $result->carrierCode);
    }

    public function testDetectsKazpostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789KZ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('kazpost', $result->carrierCode);
    }

    public function testDetectsRomaniaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789RO');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('romania-post', $result->carrierCode);
    }

    public function testDetectsCroatiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789HR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('croatia-post', $result->carrierCode);
    }

    public function testDetectsSlovakPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789SK');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('slovak-post', $result->carrierCode);
    }

    public function testDetectsSloveniaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789SI');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('slovenia-post', $result->carrierCode);
    }

    public function testDetectsSerbiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789RS');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('serbia-post', $result->carrierCode);
    }

    public function testDetectsBulgariaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BG');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bulgaria-post', $result->carrierCode);
    }

    public function testDetectsLithuaniaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789LT');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('lithuania-post', $result->carrierCode);
    }

    public function testDetectsLatviaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789LV');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('latvia-post', $result->carrierCode);
    }

    public function testDetectsMoldovaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MD');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('moldova-post', $result->carrierCode);
    }

    public function testDetectsAlbaniaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AL');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('albania-post', $result->carrierCode);
    }

    public function testDetectsMaltaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MT');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('malta-post', $result->carrierCode);
    }

    public function testDetectsLuxembourgPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789LU');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('luxembourg-post', $result->carrierCode);
    }

    public function testDetectsIcelandPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789IS');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('iceland-post', $result->carrierCode);
    }

    public function testDetectsCyprusPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CY');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('cyprus-post', $result->carrierCode);
    }

    public function testDetectsBelarusPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BY');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('belarus-post', $result->carrierCode);
    }

    public function testDetectsMacedoniaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MK');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('macedonia-post', $result->carrierCode);
    }

    public function testDetectsBosniaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bosnia-post', $result->carrierCode);
    }

    public function testDetectsDeutschePostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789DE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('deutsche-post', $result->carrierCode);
    }

    public function testDetectsMontenegroPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789ME');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('montenegro-post', $result->carrierCode);
    }

    public function testDetectsAndorraPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AD');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('andorra-post', $result->carrierCode);
    }

    public function testDetectsMonacoPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MC');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('monaco-post', $result->carrierCode);
    }

    public function testDetectsLiechtensteinPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789LI');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('liechtenstein-post', $result->carrierCode);
    }

    public function testDetectsSanMarinoPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789SM');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('san-marino-post', $result->carrierCode);
    }

    public function testDetectsVaticanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789VA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('vatican-post', $result->carrierCode);
    }

    public function testDetectsGibraltarPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789GI');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('gibraltar-post', $result->carrierCode);
    }

    public function testDetectsJerseyPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789JE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('jersey-post', $result->carrierCode);
    }

    public function testDetectsGuernseyPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789GG');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('guernsey-post', $result->carrierCode);
    }

    public function testDetectsIsleOfManPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789IM');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('isle-of-man-post', $result->carrierCode);
    }

    public function testDetectsFaroePostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789FO');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('faroe-post', $result->carrierCode);
    }

    public function testDetectsGreenlandPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789GL');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('greenland-post', $result->carrierCode);
    }

    public function testDetectsAlandPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AX');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('aland-post', $result->carrierCode);
    }

    public function testDetectsPostNlCountryCodeBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789NL');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('postnl', $result->carrierCode);
    }

    public function testDetectsColombiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CO');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('colombia-post', $result->carrierCode);
    }

    public function testDetectsPeruPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789PE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('peru-post', $result->carrierCode);
    }

    public function testDetectsUruguayPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789UY');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('uruguay-post', $result->carrierCode);
    }

    public function testDetectsParaguayPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789PY');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('paraguay-post', $result->carrierCode);
    }

    public function testDetectsBoliviaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BO');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bolivia-post', $result->carrierCode);
    }

    public function testDetectsEcuadorPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789EC');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ecuador-post', $result->carrierCode);
    }

    public function testDetectsVenezuelaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789VE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('venezuela-post', $result->carrierCode);
    }

    public function testDetectsCostaRicaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('costa-rica-post', $result->carrierCode);
    }

    public function testDetectsPanamaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789PA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('panama-post', $result->carrierCode);
    }

    public function testDetectsDominicanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789DO');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('dominican-post', $result->carrierCode);
    }

    public function testDetectsGuatemalaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789GT');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('guatemala-post', $result->carrierCode);
    }

    public function testDetectsHondurasPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789HN');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('honduras-post', $result->carrierCode);
    }

    public function testDetectsElSalvadorPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789SV');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('el-salvador-post', $result->carrierCode);
    }

    public function testDetectsNicaraguaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789NI');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('nicaragua-post', $result->carrierCode);
    }

    public function testDetectsCubaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CU');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('cuba-post', $result->carrierCode);
    }

    public function testDetectsJamaicaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789JM');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('jamaica-post', $result->carrierCode);
    }

    public function testDetectsTrinidadPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789TT');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('trinidad-post', $result->carrierCode);
    }

    public function testDetectsBarbadosPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BB');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('barbados-post', $result->carrierCode);
    }

    public function testDetectsBahamasPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BS');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bahamas-post', $result->carrierCode);
    }

    public function testDetectsSurinamePostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789SR');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('suriname-post', $result->carrierCode);
    }

    public function testDetectsGuyanaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789GY');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('guyana-post', $result->carrierCode);
    }

    public function testDetectsMoroccoPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('morocco-post', $result->carrierCode);
    }

    public function testDetectsAlgeriaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789DZ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('algeria-post', $result->carrierCode);
    }

    public function testDetectsTunisiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789TN');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('tunisia-post', $result->carrierCode);
    }

    public function testDetectsKenyaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789KE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('kenya-post', $result->carrierCode);
    }

    public function testDetectsNigeriaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789NG');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('nigeria-post', $result->carrierCode);
    }

    public function testDetectsEthiopiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789ET');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ethiopia-post', $result->carrierCode);
    }

    public function testDetectsGhanaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789GH');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ghana-post', $result->carrierCode);
    }

    public function testDetectsTanzaniaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789TZ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('tanzania-post', $result->carrierCode);
    }

    public function testDetectsUgandaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789UG');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('uganda-post', $result->carrierCode);
    }

    public function testDetectsRwandaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789RW');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('rwanda-post', $result->carrierCode);
    }

    public function testDetectsZambiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789ZM');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('zambia-post', $result->carrierCode);
    }

    public function testDetectsZimbabwePostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789ZW');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('zimbabwe-post', $result->carrierCode);
    }

    public function testDetectsMozambiquePostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MZ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('mozambique-post', $result->carrierCode);
    }

    public function testDetectsAngolaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AO');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('angola-post', $result->carrierCode);
    }

    public function testDetectsSenegalPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789SN');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('senegal-post', $result->carrierCode);
    }

    public function testDetectsIvoryCoastPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CI');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('ivory-coast-post', $result->carrierCode);
    }

    public function testDetectsCameroonPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789CM');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('cameroon-post', $result->carrierCode);
    }

    public function testDetectsMauritiusPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MU');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('mauritius-post', $result->carrierCode);
    }

    public function testDetectsQatarPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789QA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('qatar-post', $result->carrierCode);
    }

    public function testDetectsKuwaitPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789KW');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('kuwait-post', $result->carrierCode);
    }

    public function testDetectsBahrainPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BH');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bahrain-post', $result->carrierCode);
    }

    public function testDetectsBangladeshPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BD');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bangladesh-post', $result->carrierCode);
    }

    public function testDetectsNepalPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789NP');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('nepal-post', $result->carrierCode);
    }

    public function testDetectsSriLankaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789LK');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('sri-lanka-post', $result->carrierCode);
    }

    public function testDetectsMyanmarPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MM');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('myanmar-post', $result->carrierCode);
    }

    public function testDetectsCambodiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789KH');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('cambodia-post', $result->carrierCode);
    }

    public function testDetectsLaosPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789LA');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('laos-post', $result->carrierCode);
    }

    public function testDetectsMongoliaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MN');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('mongolia-post', $result->carrierCode);
    }

    public function testDetectsGeorgiaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789GE');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('georgia-post', $result->carrierCode);
    }

    public function testDetectsAzerbaijanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AZ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('azerbaijan-post', $result->carrierCode);
    }

    public function testDetectsArmeniaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AM');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('armenia-post', $result->carrierCode);
    }

    public function testDetectsUzbekistanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789UZ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('uzbekistan-post', $result->carrierCode);
    }

    public function testDetectsKyrgyzstanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789KG');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('kyrgyzstan-post', $result->carrierCode);
    }

    public function testDetectsTajikistanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789TJ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('tajikistan-post', $result->carrierCode);
    }

    public function testDetectsTurkmenistanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789TM');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('turkmenistan-post', $result->carrierCode);
    }

    public function testDetectsAfghanistanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789AF');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('afghanistan-post', $result->carrierCode);
    }

    public function testDetectsBhutanPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BT');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('bhutan-post', $result->carrierCode);
    }

    public function testDetectsMaldivesPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789MV');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('maldives-post', $result->carrierCode);
    }

    public function testDetectsBruneiPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789BN');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('brunei-post', $result->carrierCode);
    }

    public function testDetectsPapuaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789PG');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('papua-post', $result->carrierCode);
    }

    public function testDetectsFijiPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789FJ');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('fiji-post', $result->carrierCode);
    }

    public function testDetectsSamoaPostBeforeGenericFedExRule(): void
    {
        $detector = Detector::withDefaults();
        $result = $detector->detect('RA123456789WS');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('samoa-post', $result->carrierCode);
    }

    public function testDetectsPostiJjfiPrefix(): void
    {
        // JJFI 前缀 20 位；不匹配任何国内前缀规则
        $detector = Detector::withDefaults();
        $result = $detector->detect('JJFI1234567890123456');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('posti', $result->carrierCode);
    }

    public function testDetectsDelhiveryFifteenDigits(): void
    {
        // 15 位纯数字为新增位数，与 13/14/16 位规则互斥
        $detector = Detector::withDefaults();
        $result = $detector->detect('123456789012345');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('delhivery', $result->carrierCode);
    }

    public function testDetectsInpostTwentyFourDigits(): void
    {
        // 24 位纯数字为新增位数（InPost 波兰包裹柜），现有规则最长 20 位
        $detector = Detector::withDefaults();
        $result = $detector->detect('242800000000000000000000');

        $this->assertSame(Channel::International, $result->channel);
        $this->assertSame('inpost', $result->carrierCode);
    }
}
