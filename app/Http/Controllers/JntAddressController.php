<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class JntAddressController extends Controller
{
    public function index()
    {
        return view('macro_output.jnt_address');
    }

    public function search(Request $request)
    {
        $q = (string) $request->query('q', '');
        $q = $this->norm($q);

        if (mb_strlen($q, 'UTF-8') < 3) {
            return response()->json(['results' => []]);
        }

        // ✅ load + cache parsed txt
        $entries = $this->getEntries(); // each: ['prov','city','brgy','label','search']

        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $limit = min(max((int)$request->query('limit', 50), 1), 200);

        $out = [];
        foreach ($entries as $e) {
            $hay = $e['search'];

            $ok = true;
            foreach ($words as $w) {
                if ($w === '') continue;
                if (mb_strpos($hay, $w, 0, 'UTF-8') === false) {
                    $ok = false; break;
                }
            }

            if ($ok) {
                $out[] = [
                    'label' => $e['label'],
                    'prov'  => $e['prov'],
                    'city'  => $e['city'],
                    'brgy'  => $e['brgy'],
                ];
                if (count($out) >= $limit) break;
            }
        }

        return response()->json(['results' => $out]);
    }

    private function getEntries(): array
    {
        $filePath = resource_path('views/macro_output/jnt_address.txt');
        $mtime = @filemtime($filePath) ?: 0;

        $cacheKey = "jnt_addr_entries_v1_{$mtime}";

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($filePath) {
            if (!is_file($filePath)) return [];

            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $entries = [];

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') continue;

                $parts = array_map('trim', explode('|', $line));
                if (count($parts) !== 3) continue;

                // skip header if meron
                if (mb_strtolower($parts[0], 'UTF-8') === 'province') continue;

                [$prov, $city, $brgy] = $parts;

                if ($prov === '' || $city === '' || $brgy === '') continue;

                $label = "{$prov} {$city} {$brgy}";
                $entries[] = [
                    'prov'   => $prov,
                    'city'   => $city,
                    'brgy'   => $brgy,
                    'label'  => $label,
                    'search' => $this->norm($label),
                ];
            }

            return $entries;
        });
    }

    private function norm(string $s): string
    {
        $s = trim($s);
        $s = str_replace(["\xC2\xA0"], ' ', $s); // nbsp
        $s = mb_strtolower($s, 'UTF-8');
        // treat | - _ as spaces para flexible
        $s = preg_replace('/[|_\-]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}
