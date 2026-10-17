<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang mga campaign ng /chatblast: bawat isa may J&T status na nagti-trigger, mga page, window, mga araw at bersyon ng
     * text (JSON, gaya ng dating app_settings), mode (manual o auto sa mga oras ng padala) at on/off. Ang "kind" ang
     * susi sa botcake_messages.kind (kaya ang dedupe_key na waybill|kind|day ay kada campaign pa rin).
     *
     * Ang dalawang dating uri ay nagiging dalawang campaign na manual at naka-on, kasama ang naka-save nilang text (o ang
     * default na nasa code noon), at ang mga dating row ay naka-link sa kanila. Ang app_settings ay hindi binabago.
     */
    private const KINDS = [
        'in_transit' => [
            'name' => 'In transit', 'status' => 'In Transit', 'window' => 14, 'on_delivery' => false,
            'days' => [
                ["Hello Ma'am/Sir {name}, nasa biyahe na po ang order niyo ({item}). Ipapaalam po namin kapag malapit na. Salamat po!"],
                ["Hi Ma'am/Sir {name}, tuloy-tuloy po ang biyahe ng order niyo. Waybill: {waybill}. Salamat po sa paghihintay!"],
                ["Magandang araw po Ma'am/Sir {name}! Nasa daan pa rin po ang parcel niyo. Pakihanda na lang po ang PHP {cod} pagdating ng rider. Salamat po!"],
                ["Hello po Ma'am/Sir {name}, malapit na po ang order niyo mula sa {page}. Paki-abangan po ang tawag o text ng rider. Maraming salamat po!"],
                ["Hi Ma'am/Sir {name}, nasa biyahe pa rin po ang order niyo. Kung may tanong po kayo, mag-message lang po dito. Salamat po sa tiwala!"],
            ],
        ],
        'delivering' => [
            'name' => 'Delivering', 'status' => 'Delivering', 'window' => 7, 'on_delivery' => true,
            'days' => [
                ["Hello Ma'am/Sir {name}, Idedeliver po ngayon ang order niyo. Maraming salamat po sa pag-order, ingat po kayo at salamat ulit sa tiwala!"],
            ],
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('chatblast_campaigns')) {
            Schema::create('chatblast_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 30)->unique();         // ang susi sa botcake_messages.kind
                $table->string('name', 100);
                $table->json('statuses');                     // from_jnts.status na nagti-trigger
                $table->json('pages')->nullable();            // NULL = lahat ng page
                $table->unsignedSmallInteger('window_days');  // ilang araw pabalik ang order (ts_date)
                $table->json('days');                         // mga araw, bawat isa listahan ng bersyon ng text
                $table->boolean('requires_on_delivery')->default(false); // i-track bago ipadala, On Delivery lang
                $table->string('mode', 10)->default('manual');           // manual | auto
                $table->json('send_times')->nullable();                  // "HH:MM" (Manila) ng auto
                $table->boolean('active')->default(true);
                $table->timestamp('auto_ran_at')->nullable();
                $table->unsignedBigInteger('mode_changed_by')->nullable();
                $table->timestamp('mode_changed_at')->nullable();
                $table->json('log')->nullable();                         // huling mga pagbabago: sino, kailan, ano
                $table->timestamps();
            });
        }

        if (Schema::hasTable('botcake_messages') && !Schema::hasColumn('botcake_messages', 'campaign_id')) {
            Schema::table('botcake_messages', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('kind');
                $table->index('campaign_id');
            });
        }

        foreach (self::KINDS as $kind => $c) {
            if (DB::table('chatblast_campaigns')->where('kind', $kind)->exists()) continue;
            $now = now();
            $id = DB::table('chatblast_campaigns')->insertGetId([
                'kind' => $kind, 'name' => $c['name'], 'statuses' => json_encode([$c['status']]), 'pages' => null,
                'window_days' => $c['window'], 'days' => json_encode($this->saved($kind) ?? $c['days'], JSON_UNESCAPED_UNICODE),
                'requires_on_delivery' => $c['on_delivery'], 'mode' => 'manual', 'send_times' => json_encode(['09:00']),
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if (Schema::hasTable('botcake_messages')) {
                DB::table('botcake_messages')->where('kind', $kind)->whereNull('campaign_id')->update(['campaign_id' => $id]);
            }
        }
    }

    /** Ang naka-save na mga araw ng uri (mula sa dating editor), o null kapag wala o hindi buo. */
    private function saved(string $kind): ?array
    {
        if (!Schema::hasTable('app_settings')) return null;
        $days = json_decode((string) DB::table('app_settings')->where('key', 'chatblast.sequence.' . $kind)->value('value'), true);
        if (!is_array($days) || !$days || !array_is_list($days)) return null;
        foreach ($days as $versions) {
            if (!is_array($versions) || !$versions || !array_is_list($versions)) return null;
            foreach ($versions as $text) {
                if (!is_string($text) || trim($text) === '') return null;
            }
        }

        return $days;
    }

    public function down(): void
    {
        if (Schema::hasColumn('botcake_messages', 'campaign_id')) {
            Schema::table('botcake_messages', function (Blueprint $table) {
                $table->dropIndex(['campaign_id']);
                $table->dropColumn('campaign_id');
            });
        }
        Schema::dropIfExists('chatblast_campaigns');
    }
};
