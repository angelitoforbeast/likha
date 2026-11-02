<?php

namespace App\Jobs\Concerns;

use Illuminate\Queue\SerializesModels;

/**
 * Ang SerializesModels, kasama ang pagbasa ng payload na mas luma sa klase: ang typed property na wala sa payload (idinagdag
 * pagkatapos ma-queue ang job) ay kinukuha ang default ng parameter ng constructor na may parehong pangalan, gaya ng bagong
 * dispatch. Ang pagsulat ng payload (__serialize) ay ang sa SerializesModels, walang binago: ang lumang code ay nakakabasa pa.
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
