<?php

namespace App\Services\OrderEncode;

use App\Models\OrderEncode;

/**
 * Ang usapan na binabasa ng live na encode: ang mga mensahe ng order na ito lang. Ang input cell ng flow ay maaaring
 * {{ALL USER INPUT}} (ang huling 50 mensahe ng customer), kaya dala nito ang naunang order. Bawat takbo ay may sariling
 * input.row (ang binasa); ang bago ay ang mga linya pagkatapos ng binasa ng naunang takbo ng customer. Ang takbong naka-link
 * sa bukas na row (sagot sa tanong ng address) ay idinudugtong sa usapan ng naunang takbo ng row na iyon.
 */
final class Conversation
{
    /** Pinakamahabang usapan na ipinapasa sa GPT (ang pinakabago ang naiiwan). */
    public const MAX = 8000;

    public function build(OrderEncode $o, Input $in): Input
    {
        $v = $in->v;
        if ($o->psid) {
            $prior = OrderEncode::where('mode', 'live')->where('page', $o->page)->where('psid', $o->psid)->where('id', '<', $o->id)->orderByDesc('id')->first();
            $new = $prior ? self::after(self::read($prior), $in->get('input')) : $in->get('input');
            $base = !empty($o->input['linked']) ? $this->base($o) : null;
            if ($base) {
                $f = (array) $base->input['fields'];
                $v['input'] = self::join((string) ($f['input'] ?? ''), $new);
                $v['cxd'] = self::merge((string) ($f['cxd'] ?? ''), $in->get('cxd'));
            } else {
                // Walang bagong linya (hal. parehong cell): ang buong cell, hindi blangkong order.
                $v['input'] = trim($new) !== '' ? $new : $in->get('input');
            }
        }
        $v['input'] = self::cap($v['input'], self::MAX);
        $v['cxd'] = self::cap($v['cxd'], 4000);

        return new Input($v);
    }

    /** Ang huling na-encode na takbo ng parehong row (ang orihinal na order, o ang naunang sagot). */
    private function base(OrderEncode $o): ?OrderEncode
    {
        return OrderEncode::where('macro_output_id', $o->macro_output_id)->where('id', '<', $o->id)->orderByDesc('id')->limit(20)->get()
            ->first(fn (OrderEncode $e) => isset($e->input['fields']));
    }

    /** Ang input cell na binasa ng isang takbo (E ng row). */
    private static function read(OrderEncode $o): string
    {
        return Input::fromRow((array) ($o->input['row'] ?? []))->get('input');
    }

    /** Ang mga linya ng $new pagkatapos ng pinakamahabang dulo ng $old na siyang simula ng $new. */
    private static function after(string $old, string $new): string
    {
        $o = self::lines($old);
        $n = self::lines($new);
        for ($k = min(count($o), count($n)); $k > 0; $k--) {
            if (array_slice($o, -$k) === array_slice($n, 0, $k)) return implode("\n", array_slice($n, $k));
        }

        return $new;
    }

    /** @return array<int, string> */
    private static function lines(string $s): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $s)), fn ($l) => $l !== ''));
    }

    private static function join(string $a, string $b): string
    {
        return trim($a) === '' ? $b : (trim($b) === '' ? $a : $a . "\n" . $b);
    }

    /** Ang extracted details ng sagot ay idinudugtong sa nauna (ang huling linya ng label ang nasusunod sa Parse). */
    private static function merge(string $old, string $new): string
    {
        return trim($old) !== '' && str_contains($new, trim($old)) ? $new : self::join($old, $new);
    }

    private static function cap(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, -$max) : $s;
    }
}
