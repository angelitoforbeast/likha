<?php

namespace App\Boardroom\Orchestrator;

use App\Boardroom\Support\Secrets;
use App\Models\Boardroom\Change;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\PaymentAccount;
use App\Models\Boardroom\ResourceEntry;
use Illuminate\Support\Collection;

/**
 * Resources registry: saan nakalagay ang impormasyon at sino ang mga contact. Para sa LAHAT ng role.
 *
 * Ang AI ang nagmumungkahi ng mga pagbabago (add / edit / archive) mula sa sinabi ng user; ang backend na ito
 * ang nagva-validate at nagse-save. Ang sinasabi ng AI ay HINDI pinagkakatiwalaan nang basta:
 *
 *   - Walang "delete" para sa AI — archive lang, na maibabalik.
 *   - Ang mga ID ay dapat sa user na ito at aktibo pa.
 *   - Ang account number ay ise-save lang kung EKSAKTONG makikita sa message ng user (iwas maling digit).
 *   - Bawat pagbabago ay may tala (dati → bago) at pwedeng i-undo.
 *   - Mga br_* table lang ang nagagalaw; hindi ang suppliers / PO / orders ng website.
 */
class Registry
{
    public const MAX_IN_CONTEXT = 80;
    public const MAX_CHANGES    = 20;

    // Hindi ito lugar para sa mga lihim na pang-login o pang-bayad.
    private const FORBIDDEN       = '/\b(password|passcode|pass\s*word|pin|otp|cvv|cvc|security\s*code|mpin)\b/i';
    private const FORBIDDEN_VALUE = '/\b(password|passcode|pass\s*word|pin|otp|cvv|cvc|security\s*code|mpin)\b\s*(:|=|\bay\b|\bis\b)\s*\S+/i';

    /** @var array<int, string> mga account number na na-save sa huling apply() — para ma-mask din sa sagot ng role */
    private array $savedNumbers = [];

    // ───────────────────────── Context para sa mga role ─────────────────────────

    public function forContext(int $userId, ?int $projectId): Collection
    {
        return ResourceEntry::with(['accounts' => fn ($q) => $q->where('status', 'active')->orderBy('id')])
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->orderByDesc('id')
            ->limit(self::MAX_IN_CONTEXT)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /** Ang registry bilang text. Huling 4 na digit lang ng account number ang kasama. */
    public function block(int $userId, ?int $projectId): string
    {
        $rows = $this->forContext($userId, $projectId);
        $out  = "## RESOURCES (company registry: where information lives and who the contacts are)\n";
        if ($rows->isEmpty()) {
            return $out . "(nothing recorded yet)\n\n";
        }

        $names = $rows->pluck('name', 'id');
        foreach ($rows as $r) {
            $parts = [];
            if ($r->parent_id) {
                $parts[] = 'belongs to: ' . ($names[$r->parent_id] ?? 'resource') . " (#{$r->parent_id})";
            }
            foreach (['purpose' => 'used for', 'location' => 'where', 'holder' => 'who has access', 'details' => 'notes'] as $field => $label) {
                if (trim((string) $r->$field) !== '') {
                    $parts[] = "{$label}: " . mb_substr(trim((string) $r->$field), 0, 400);
                }
            }
            if ($r->tags) {
                $parts[] = 'tags: ' . implode(', ', (array) $r->tags);
            }
            $out .= "- resource_id={$r->id} [{$r->type}] {$r->name}" . ($parts ? ' — ' . implode('; ', $parts) : '') . "\n";

            foreach ($r->accounts as $a) {
                $out .= "    · account_id={$a->id} {$a->method}"
                    . ($a->account_name ? " — {$a->account_name}" : '')
                    . ' — ' . $a->masked()
                    . ($a->notes ? " — {$a->notes}" : '') . "\n";
            }
        }

        return $out . "\n";
    }

    // ───────────────────────── Mga pagbabagong iminungkahi ng AI ─────────────────────────

    /**
     * I-validate at i-save ang mga pagbabago. Tinatawag sa loob ng transaction ng turn.
     *
     * @param  array<int, array>  $changes  galing sa sagot ng role
     * @return array{applied: array<int, Change>, rejected: array<int, string>, numbers: array<int, string>}
     */
    public function apply(Meeting $m, array $member, array $changes, ?Message $question): array
    {
        $applied  = [];
        $rejected = [];
        $this->savedNumbers = [];
        $added    = [];   // name_key → ResourceEntry na idinagdag sa batch na ito (para sa parent_name)
        $meta     = ['actor' => 'ai', 'agent_id' => (int) $member['agent_id'], 'meeting_id' => $m->id, 'message_id' => $question?->id];

        if (count($changes) > self::MAX_CHANGES) {
            $rejected[] = 'Sobra sa ' . self::MAX_CHANGES . ' ang pagbabago sa isang message; ang una lang ang ginawa.';
            $changes    = array_slice($changes, 0, self::MAX_CHANGES);
        }

        foreach ($changes as $c) {
            if (! is_array($c)) {
                continue;
            }
            $op     = (string) ($c['op'] ?? '');
            $target = (string) ($c['target'] ?? '');
            $name   = trim((string) ($c['name'] ?? $c['account_name'] ?? ''));

            try {
                $change = match ("{$op}:{$target}") {
                    'add:resource'     => $this->addResource($m, $c, $meta, $added),
                    'edit:resource'    => $this->editResource($m, $c, $meta, $added),
                    'archive:resource' => $this->archiveResource($m, $c, $meta),
                    'add:account'      => $this->addAccount($m, $c, $meta, $added, $question),
                    'edit:account'     => $this->editAccount($m, $c, $meta, $question),
                    'archive:account'  => $this->archiveAccount($m, $c, $meta),
                    default            => throw new \DomainException($op === 'delete'
                        ? 'hindi pwedeng magbura ang AI — archive lang'
                        : "hindi kilalang pagbabago ({$op} {$target})"),
                };
                if ($change) {
                    $applied[] = $change;
                }
            } catch (\DomainException $e) {
                $rejected[] = trim(($name !== '' ? "{$name}: " : '') . $e->getMessage());
            }
        }

        return ['applied' => $applied, 'rejected' => $rejected, 'numbers' => $this->savedNumbers];
    }

    /** Palitan ng ****1234 ang mga buong account number sa isang text (hal. sa sagot ng role). */
    public function maskNumbers(string $text, array $numbers): string
    {
        foreach ($numbers as $number) {
            $clean = PaymentAccount::normalize((string) $number);
            if ($clean !== '') {
                $text = preg_replace($this->numberPattern($clean), '****' . mb_substr($clean, -4), $text) ?? $text;
            }
        }

        return $text;
    }

    private function addResource(Meeting $m, array $c, array $meta, array &$added): ?Change
    {
        $type = (string) ($c['type'] ?? '');
        if (! in_array($type, ResourceEntry::TYPES, true)) {
            throw new \DomainException("hindi kilalang klase \"{$type}\"");
        }
        $name = $this->text($c['name'] ?? null, 200);
        if ($name === null) {
            throw new \DomainException('walang pangalan');
        }
        $this->guard($c);

        $key  = ResourceEntry::key($name);
        $same = ResourceEntry::where('user_id', $m->user_id)->where('status', 'active')->where('type', $type)->where('name_key', $key)->first();
        if ($same) {
            $added[$key] = $same;
            throw new \DomainException("meron na (#{$same->id}) — hindi na idinagdag ulit");
        }

        $r = ResourceEntry::create([
            'user_id'    => $m->user_id,
            'project_id' => null,
            'type'       => $type,
            'name'       => $name,
            'purpose'    => $this->text($c['purpose'] ?? null, 500),
            'tags'       => $this->tags($c['tags'] ?? null),
            'location'   => $this->text($c['location'] ?? null, 1000),
            'parent_id'  => $this->parent($m, $c, $added)?->id,
            'details'    => $this->text($c['details'] ?? null, 4000),
            'holder'     => $this->text($c['holder'] ?? null, 200),
            'status'     => 'active',
            'source'     => 'chat',
            'agent_id'   => $meta['agent_id'],
            'meeting_id' => $meta['meeting_id'],
            'message_id' => $meta['message_id'],
        ]);
        $added[$key] = $r;

        return $this->log($m->user_id, 'resource', $r->id, 'add', "Idinagdag: [{$r->type}] {$r->name}", null, $r->snapshot(), $meta);
    }

    private function editResource(Meeting $m, array $c, array $meta, array &$added): ?Change
    {
        $r = $this->resource($m, $c['id'] ?? null);
        $this->guard($c);
        $before = $r->snapshot();

        if (isset($c['type']) && $c['type'] !== null) {
            if (! in_array($c['type'], ResourceEntry::TYPES, true)) {
                throw new \DomainException("hindi kilalang klase \"{$c['type']}\"");
            }
            $r->type = $c['type'];
        }
        foreach (['name' => 200, 'purpose' => 500, 'location' => 1000, 'details' => 4000, 'holder' => 200] as $field => $max) {
            $value = $this->text($c[$field] ?? null, $max);
            if ($value !== null) {
                $r->$field = $value;
            }
        }
        if (is_array($c['tags'] ?? null) && $c['tags']) {
            $r->tags = $this->tags($c['tags']);
        }
        $parent = $this->parent($m, $c, $added);
        if ($parent && $parent->id !== $r->id) {
            $r->parent_id = $parent->id;
        }

        if (! $r->isDirty()) {
            return null;   // walang totoong nabago
        }
        $r->save();

        return $this->log($m->user_id, 'resource', $r->id, 'edit', "Binago: [{$r->type}] {$r->name}", $before, $r->snapshot(), $meta);
    }

    private function archiveResource(Meeting $m, array $c, array $meta): ?Change
    {
        $r      = $this->resource($m, $c['id'] ?? null);
        $before = $r->snapshot();
        $r->forceFill(['status' => 'archived'])->save();

        return $this->log($m->user_id, 'resource', $r->id, 'archive', "In-archive: [{$r->type}] {$r->name}", $before, $r->snapshot(), $meta);
    }

    private function addAccount(Meeting $m, array $c, array $meta, array &$added, ?Message $question): ?Change
    {
        $owner = $this->parent($m, $c, $added);
        if (! $owner) {
            throw new \DomainException('hindi matukoy kung kaninong contact ang account');
        }
        $method = $this->text($c['method'] ?? null, 60);
        if ($method === null) {
            throw new \DomainException('walang paraan ng bayad (hal. GCash, BDO)');
        }
        $this->guard($c);
        $number = $this->exactNumber($c['account_number'] ?? null, $question);

        foreach (PaymentAccount::where('resource_id', $owner->id)->where('status', 'active')->get() as $existing) {
            if (PaymentAccount::normalize((string) $existing->number()) === PaymentAccount::normalize($number)) {
                throw new \DomainException("meron na ang account na ito kay {$owner->name} — hindi na idinagdag ulit");
            }
        }

        $a = new PaymentAccount([
            'user_id'      => $m->user_id,
            'resource_id'  => $owner->id,
            'method'       => $method,
            'account_name' => $this->text($c['account_name'] ?? null, 200),
            'notes'        => $this->text($c['notes'] ?? null, 500),
            'status'       => 'active',
            'source'       => 'chat',
            'agent_id'     => $meta['agent_id'],
            'meeting_id'   => $meta['meeting_id'],
            'message_id'   => $meta['message_id'],
        ]);
        $a->setNumber($number);
        $a->save();
        $this->maskInMessage($question, $number);

        return $this->log($m->user_id, 'account', $a->id, 'add', "Idinagdag: {$a->method} {$a->masked()} ni {$owner->name}", null, $a->snapshot(), $meta);
    }

    private function editAccount(Meeting $m, array $c, array $meta, ?Message $question): ?Change
    {
        $a = $this->account($m, $c['id'] ?? null);
        $this->guard($c);
        $before = $a->snapshot();

        foreach (['method' => 60, 'account_name' => 200, 'notes' => 500] as $field => $max) {
            $value = $this->text($c[$field] ?? null, $max);
            if ($value !== null) {
                $a->$field = $value;
            }
        }
        if (trim((string) ($c['account_number'] ?? '')) !== '') {
            $number = $this->exactNumber($c['account_number'], $question);
            if (PaymentAccount::normalize((string) $a->number()) !== PaymentAccount::normalize($number)) {
                $a->setNumber($number);
                $this->maskInMessage($question, $number);
            }
        }

        if (! $a->isDirty()) {
            return null;
        }
        $a->save();
        $owner = ResourceEntry::find($a->resource_id);

        return $this->log($m->user_id, 'account', $a->id, 'edit', "Binago: {$a->method} {$a->masked()} ni " . ($owner?->name ?? 'contact'), $before, $a->snapshot(), $meta);
    }

    private function archiveAccount(Meeting $m, array $c, array $meta): ?Change
    {
        $a      = $this->account($m, $c['id'] ?? null);
        $before = $a->snapshot();
        $a->forceFill(['status' => 'archived'])->save();
        $owner = ResourceEntry::find($a->resource_id);

        return $this->log($m->user_id, 'account', $a->id, 'archive', "In-archive: {$a->method} {$a->masked()} ni " . ($owner?->name ?? 'contact'), $before, $a->snapshot(), $meta);
    }

    // ───────────────────────── Undo ─────────────────────────

    /**
     * Bawiin ang isang pagbabago. Hindi pinapayagan kung may MAS BAGONG pagbabago sa parehong record,
     * para hindi matabunan ang mas bagong edit.
     *
     * @return array{ok: bool, message: string}
     */
    public function undo(Change $change): array
    {
        if ($change->undone_at) {
            return ['ok' => false, 'message' => 'Na-undo na ito.'];
        }
        $newer = Change::where('target_type', $change->target_type)->where('target_id', $change->target_id)
            ->where('id', '>', $change->id)->whereNull('undone_at')->exists();
        if ($newer) {
            return ['ok' => false, 'message' => 'May mas bagong pagbabago sa record na ito. I-edit na lang sa Resources page.'];
        }

        $record = $change->target_type === 'account'
            ? PaymentAccount::where('user_id', $change->user_id)->find($change->target_id)
            : ResourceEntry::where('user_id', $change->user_id)->find($change->target_id);
        if (! $record) {
            return ['ok' => false, 'message' => 'Wala na ang record (nabura na nang tuluyan).'];
        }

        if ($change->action === 'add') {
            $record->forceFill(['status' => 'archived'])->save();   // undo ng dagdag = archive, hindi bura
        } elseif (is_array($change->before)) {
            $record->forceFill($change->before)->save();            // edit / archive / restore: ibalik ang dati
        }
        $change->forceFill(['undone_at' => now()])->save();

        return ['ok' => true, 'message' => 'Na-undo.'];
    }

    // ───────────────────────── Tala ─────────────────────────

    public function log(int $userId, string $type, int $id, string $action, string $label, ?array $before, ?array $after, array $meta): Change
    {
        return Change::create([
            'user_id'     => $userId,
            'target_type' => $type,
            'target_id'   => $id,
            'action'      => $action,
            'label'       => mb_substr($label, 0, 300),
            'before'      => $before,
            'after'       => $after,
            'actor'       => $meta['actor'] ?? 'user',
            'agent_id'    => $meta['agent_id'] ?? null,
            'meeting_id'  => $meta['meeting_id'] ?? null,
            'message_id'  => $meta['message_id'] ?? null,
        ]);
    }

    /** Ang paalala sa chat para sa isang batch ng pagbabago. */
    public function notice(Meeting $m, array $member, array $result, ?Message $question): ?Message
    {
        if (! $result['applied'] && ! $result['rejected']) {
            return null;
        }
        $lines = [];
        foreach ($result['applied'] as $change) {
            $lines[] = $change->label;
        }
        foreach ($result['rejected'] as $why) {
            $lines[] = "Hindi na-save — {$why}";
        }

        return Message::create([
            'meeting_id'          => $m->id,
            'author_type'         => 'system',
            'agent_id'            => (int) $member['agent_id'],
            'kind'                => 'changes',
            'cycle'               => (int) $m->cycle,
            'reply_to_message_id' => $question?->id,
            'body'                => "Itinala ni {$member['handle']} sa Resources:\n- " . implode("\n- ", $lines),
            'meta'                => [
                'change_ids' => array_map(fn (Change $c) => $c->id, $result['applied']),
                'rejected'   => $result['rejected'],
            ],
        ]);
    }

    // ───────────────────────── Mga pananggalang ─────────────────────────

    /** Ang record ay dapat sa user na ito at aktibo pa. */
    private function resource(Meeting $m, mixed $id): ResourceEntry
    {
        $r = is_numeric($id) ? ResourceEntry::where('user_id', $m->user_id)->where('status', 'active')->find((int) $id) : null;
        if (! $r) {
            throw new \DomainException('walang aktibong record na may id ' . json_encode($id));
        }

        return $r;
    }

    private function account(Meeting $m, mixed $id): PaymentAccount
    {
        $a = is_numeric($id) ? PaymentAccount::where('user_id', $m->user_id)->where('status', 'active')->find((int) $id) : null;
        if (! $a) {
            throw new \DomainException('walang aktibong account na may id ' . json_encode($id));
        }

        return $a;
    }

    /** Kanino kabilang: sa id ng existing na record, o sa pangalan ng record (existing o kadaragdag lang). */
    private function parent(Meeting $m, array $c, array $added): ?ResourceEntry
    {
        if (is_numeric($c['parent_id'] ?? null)) {
            $found = ResourceEntry::where('user_id', $m->user_id)->where('status', 'active')->find((int) $c['parent_id']);
            if ($found) {
                return $found;
            }
        }
        $name = trim((string) ($c['parent_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $key = ResourceEntry::key($name);

        return $added[$key]
            ?? ResourceEntry::where('user_id', $m->user_id)->where('status', 'active')->where('name_key', $key)->orderByDesc('id')->first();
    }

    /**
     * Ang account number ay tinatanggap lang kung EKSAKTONG nasa message ng user
     * (hindi binibilang ang espasyo at gitling). Ito ang pananggalang laban sa maling digit.
     */
    private function exactNumber(mixed $number, ?Message $question): string
    {
        $raw   = trim((string) (is_scalar($number) ? $number : ''));
        $clean = PaymentAccount::normalize($raw);
        if ($clean === '' || ! preg_match('/^[A-Za-z0-9]{6,34}$/', $clean)) {
            throw new \DomainException('hindi mukhang account number ang ibinigay');
        }
        if (! $question || ! preg_match($this->numberPattern($clean), (string) $question->body)) {
            throw new \DomainException('hindi tugma ang account number sa message mo — hindi na-save (iwas maling digit)');
        }

        return $raw;
    }

    /** Pattern na tumutugma sa numero kahit may espasyo o gitling sa pagitan ng mga character. */
    private function numberPattern(string $clean): string
    {
        $chars = array_map(fn ($ch) => preg_quote($ch, '/'), preg_split('//u', $clean, -1, PREG_SPLIT_NO_EMPTY));

        return '/(?<![A-Za-z0-9])' . implode('[\s\-]*', $chars) . '(?![A-Za-z0-9])/u';
    }

    /** Pagka-save, palitan ang buong numero sa message ng user — para hindi na ito maipadala sa AI sa mga susunod na call. */
    private function maskInMessage(?Message $question, string $number): void
    {
        $this->savedNumbers[] = $number;
        if (! $question) {
            return;
        }
        $clean  = PaymentAccount::normalize($number);
        $masked = '****' . mb_substr($clean, -4);
        $body   = preg_replace($this->numberPattern($clean), $masked, (string) $question->body);
        if (is_string($body) && $body !== $question->body) {
            $meta = (array) $question->meta;
            $meta['masked_account_numbers'] = true;
            $question->forceFill(['body' => $body, 'meta' => $meta])->save();
        }
    }

    /** Bawal itala rito ang password, PIN, OTP, at CVV. */
    private function guard(array $c): void
    {
        foreach (['name', 'purpose', 'method', 'account_name', 'notes', 'type'] as $field) {
            if (is_string($c[$field] ?? null) && preg_match(self::FORBIDDEN, $c[$field])) {
                throw new \DomainException('hindi itinatala rito ang password, PIN, OTP, o CVV');
            }
        }
        // Sa malayang text: bawal lang kapag may kasamang halaga (hal. "password: abc123").
        foreach (['details', 'location'] as $field) {
            if (is_string($c[$field] ?? null) && preg_match(self::FORBIDDEN_VALUE, $c[$field])) {
                throw new \DomainException('hindi itinatala rito ang password, PIN, OTP, o CVV');
            }
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(Secrets::redact($value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function tags(mixed $tags): ?array
    {
        if (! is_array($tags)) {
            return null;
        }
        $out = [];
        foreach ($tags as $t) {
            $t = is_string($t) ? mb_strtolower(trim($t)) : '';
            if ($t !== '' && mb_strlen($t) <= 40) {
                $out[$t] = true;
            }
        }

        return $out ? array_slice(array_keys($out), 0, 12) : null;
    }
}
