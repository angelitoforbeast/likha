<?php

namespace App\Support;

/**
 * Ang dahilan ng row ng chatblast para sa page: ang mga row bago ang English ay may dahilang Tagalog na nakatala; dito
 * isinasalin ang mga kilalang anyo nito (kasama ang mga detalye: page, araw, status, HTTP, bilang) para ipakita. Hindi
 * kailanman isinusulat sa row. Ang hindi kilala (hal. "Error: …" mula sa exception) ay ibinabalik nang ganoon.
 */
class ChatblastReason
{
    /** [regex ng buong dahilan, kapalit]; magkakasunod, ang una na tumapat ang ginagamit. */
    private const LEGACY = [
        ['/^walang rider info$/u', 'no rider info'],
        ['/^walang (\{[a-z_]+\})$/u', 'missing $1'],
        ['/^walang PSID ang order \(hindi pa tugma sa Botcake customer\)$/u', 'the order has no PSID (not matched to a Botcake customer yet)'],
        ['/^walang PSID$/u', 'no PSID'],
        ['/^hindi buo ang text: walang laman$/u', 'incomplete text: empty'],
        ['/^hindi buo ang text: may (.+) pa$/u', 'incomplete text: $1 is still in it'],
        ['/^Hindi na On Delivery ang huling scan ng J&T \(walang scantype\)$/u', 'The latest J&T scan is no longer On Delivery (no scantype)'],
        ['/^Hindi na On Delivery ang huling scan ng J&T \((.*)\)$/u', 'The latest J&T scan is no longer On Delivery ($1)'],
        ['/^Wala nang Araw (\d+) sa mga araw ng uri$/u', 'Day $1 no longer exists in the sequence'],
        ['/^Wala na ang page na (.+) sa campaign$/u', 'Page "$1" is no longer in the sequence'],
        ['/^Hindi na (.+) ang waybill \(ngayon: wala sa J&T\)$/u', 'The waybill is no longer $1 (now: not in J&T)'],
        ['/^Hindi na (.+) ang waybill \(ngayon: (.*)\)$/u', 'The waybill is no longer $1 (now: $2)'],
        ['/^May naipadala nang mensahe ng uring ito sa waybill ngayong araw; ipadala bukas$/u', 'This sequence already sent a message to the waybill today; send it tomorrow'],
        ['/^may naipadala na ngayong araw sa ibang campaign$/u', 'already sent today by another sequence'],
        ['/^Naabot ang daily cap ng campaign sa page \((\d+) ngayong araw\); ipadala ulit bukas$/u', 'Sequence daily cap reached for the page ($1 today); send again tomorrow'],
        ['/^Naabot ang daily cap ng page \((\d+) ngayong araw\); ipadala ulit bukas$/u', 'Page daily cap reached ($1 today); send again tomorrow'],
        ['/^Naka-off ang campaign$/u', 'The sequence is off'],
        ['/^Walang campaign ang mensaheng ito$/u', 'This message has no sequence'],
        ['/^Hindi tiyak kung naipadala \(tinawag na ang send_flow\); pinatay ang job\. Tingnan sa Botcake bago magpadala ulit$/u', 'Not sure it was sent (send_flow was called); the job was killed. Check Botcake before sending again'],
        ['/^Hindi tiyak kung naipadala \(tinawag na ang send_flow\) \(HTTP (\d+)\); tingnan sa Botcake bago magpadala ulit$/u', 'Not sure it was sent (send_flow was called) (HTTP $1); check Botcake before sending again'],
        ['/^Hindi tiyak kung naipadala \(tinawag na ang send_flow\)(.*)$/u', 'Not sure it was sent (send_flow was called)$1'],
        ['/^Pinatay ang job bago tinawag ang send_flow \(hal\. timeout\); ligtas ipadala ulit$/u', 'The job was killed before send_flow was called (e.g. a timeout); safe to send again'],
        ['/^nagbago ang PSID\/page$/u', 'the PSID/page changed'],
        ['/^Token invalid: ayusin sa \/page-settings \(Test token\) bago magpadala$/u', 'Token invalid: fix it on /page-settings (Test token) before sending'],
        ['/^Token invalid \(HTTP (\d+)\); ayusin ang token sa \/page-settings$/u', 'Token invalid (HTTP $1); fix the token on /page-settings'],
        ['/^Huminto: token invalid$/u', 'Stopped: token invalid'],
        ['/^Hindi naisulat ang custom field \(HTTP (\d+)\)$/u', 'The custom field was not written (HTTP $1)'],
        ['/^Hindi nabasa ang custom field \(HTTP (\d+)\); hindi ipinadala$/u', 'The custom field could not be read (HTTP $1); not sent'],
        ['/^Wala ang field (.+) sa nabasa; hindi ipinadala$/u', 'Field $1 is missing in the read-back; not sent'],
        ['/^Iba ang laman ng field sa nabasa; hindi ipinadala$/u', 'The field holds a different text in the read-back; not sent'],
        ['/^Hindi na-send ang flow \(HTTP (\d+)\)$/u', 'The flow was not sent (HTTP $1)'],
        // Ang "walang <mga setting>" (no_config) ay huli: ang mas tiyak na "walang …" ay nasa itaas.
        ['/^walang ([a-z_.]+(?:, [a-z_.]+)*)$/u', 'missing $1'],
    ];

    /** Ang dahilan sa English para ipakita (ang naka-English na ay hindi nagbabago). */
    public static function display(?string $reason): ?string
    {
        if ($reason === null || $reason === '') return $reason;
        foreach (self::LEGACY as [$pattern, $english]) {
            if (preg_match($pattern, $reason)) return preg_replace($pattern, $english, $reason);
        }

        return $reason;
    }
}
