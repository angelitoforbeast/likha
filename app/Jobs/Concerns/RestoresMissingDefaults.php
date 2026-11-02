<?php

namespace App\Jobs\Concerns;

use Illuminate\Queue\SerializesModels;

/**
 * Ang SerializesModels, kasama ang pagbasa ng payload na mas luma sa klase: ang typed property na wala sa payload (idinagdag
 * pagkatapos ma-queue ang job) ay kinukuha ang default ng parameter ng constructor na may parehong pangalan, gaya ng bagong
 * dispatch. Ang pagsulat ng payload (__serialize) ay ang sa SerializesModels, walang binago: ang lumang code ay nakakabasa pa.
 *
 * Ang ibinabalik ay ang raw na default ng parameter, hindi ang resulta ng constructor body. Kaya gumagana lang ito kapag ang
 * property ay promoted, o ina-assign nang diretso mula sa parameter na may parehong pangalan at parehong type. Ang property
 * na kinukuwenta sa constructor (hal. now()->addHours(6)) ay kailangan ng class default. Ang guard sa QueuedJobCompatTest
 * (parehong pangalan, parehong type) ang nagpapatupad nito.
 */
trait RestoresMissingDefaults
{
    use SerializesModels {
        __unserialize as private unserializeModels;
    }

    public function __unserialize(array $values): void
    {
        $this->unserializeModels($values);
        $this->restoreMissingDefaults();
    }

    private function restoreMissingDefaults(): void
    {
        $r = new \ReflectionObject($this);
        $defaults = [];
        foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
            if ($p->isDefaultValueAvailable()) $defaults[$p->getName()] = $p->getDefaultValue();
        }
        foreach ($r->getProperties() as $prop) {
            if ($prop->isStatic() || !$prop->hasType() || $prop->isInitialized($this) || !array_key_exists($prop->getName(), $defaults)) continue;
            $prop->setValue($this, $defaults[$prop->getName()]);
        }
    }
}
