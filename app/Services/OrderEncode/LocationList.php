<?php

namespace App\Services\OrderEncode;

/**
 * Ang listahan ng lugar ng order encode: ang J&T address file ng Likha (resources/views/macro_output/jnt_address.txt, isang
 * PROVINCE|CITY|BARANGAY kada linya), binabasa lang, ayon sa ayos ng file (mahalaga sa "unang candidate"). Iisa ito para sa
 * dalawang hakbang (ang Macro match at ang Locations ng checker). Naka-cache sa proseso ayon sa mtime ng file, gaya ng ibang
 * reader. Ang file ay hindi ginagalaw at ang ibang reader nito (Astra, MacroChecker) ay hindi tinatawag.
 */
class LocationList
{
    /** @var array<string, array{0: int, 1: array<int, array<int, string>>, 2: ?Macro, 3: ?Locations, 4: ?Locations}> path => [mtime, rows, macro, locations, exact locations] */
    private static array $memo = [];

    public function __construct(private ?string $path = null)
    {
    }

    public function path(): string
    {
        return $this->path ?? resource_path('views/macro_output/jnt_address.txt');
    }

    public function has(): bool
    {
        return is_file($this->path());
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> province, city, barangay ng bawat linya; [] kapag wala ang file */
    public function rows(): array
    {
        return $this->load()[1];
    }

    /** Ang AUTO ENCODE match (FILTER ng ENCODER) sa listahan. */
    public function macro(): ?Macro
    {
        $path = $this->path();
        $entry = $this->load();
        if (!$entry[1]) return null;

        return self::$memo[$path][2] ??= new Macro($entry[1]);
    }

    /** Ang mga mapa ng checker (T/U/V at list checks) sa parehong listahan. */
    public function locations(): ?Locations
    {
        $path = $this->path();
        $entry = $this->load();
        if (!$entry[1]) return null;

        return self::$memo[$path][3] ??= new Locations($entry[1]);
    }

    /** Ang mga mapa ng checker na ang mga barangay ay eksaktong gaya ng sa J&T file (mga panuntunang "improved"). */
    public function exactLocations(): ?Locations
    {
        $path = $this->path();
        $entry = $this->load();
        if (!$entry[1]) return null;

        return self::$memo[$path][4] ??= new Locations($entry[1], true);
    }

    /** @return array{0: int, 1: array<int, array<int, string>>} */
    private function load(): array
    {
        $path = $this->path();
        if (!is_file($path)) return [0, []];
        $mtime = (int) filemtime($path);
        if ((self::$memo[$path][0] ?? null) === $mtime) return self::$memo[$path];

        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $p = explode('|', $line);
            if (count($p) < 3) continue;
            $rows[] = [trim($p[0]), trim($p[1]), trim($p[2])];
        }

        return self::$memo[$path] = [$mtime, $rows, null, null, null];
    }
}
