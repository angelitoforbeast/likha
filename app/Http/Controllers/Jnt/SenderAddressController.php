<?php

namespace App\Http\Controllers\Jnt;

use App\Http\Controllers\Controller;
use App\Models\SenderAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class SenderAddressController extends Controller
{
    private function addressTxtPath(): string
    {
        // ✅ as you said: resources/views/macro_output/jnt_address.txt
        return base_path('resources/views/macro_output/jnt_address.txt');
    }

    /**
     * Parse jnt_address.txt lines:
     * PROV|CITY|BRGY  (your BRGY is "AREA" in J&T payload)
     *
     * Returns:
     * [
     *   'list' => [ ['prov'=>..., 'city'=>..., 'brgy'=>...], ... ],
     *   'index' => [ 'PROV' => [ 'CITY' => ['BRGY1','BRGY2'] ] ]
     * ]
     */
    private function loadAddressLibrary(): array
    {
        $path = $this->addressTxtPath();

        $list = [];
        $index = [];

        if (!File::exists($path)) {
            return ['list' => [], 'index' => []];
        }

        $lines = File::lines($path);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3) continue;

            [$prov, $city, $brgy] = [$parts[0], $parts[1], $parts[2]];
            if ($prov === '' || $city === '' || $brgy === '') continue;

            $list[] = ['prov' => $prov, 'city' => $city, 'brgy' => $brgy];

            $index[$prov] ??= [];
            $index[$prov][$city] ??= [];
            if (!in_array($brgy, $index[$prov][$city], true)) {
                $index[$prov][$city][] = $brgy;
            }
        }

        // sort for nicer dropdown UX
        ksort($index);
        foreach ($index as $p => $cities) {
            ksort($index[$p]);
            foreach ($index[$p] as $c => $brgys) {
                sort($index[$p][$c], SORT_STRING);
            }
        }

        return ['list' => $list, 'index' => $index];
    }

    private function isValidCombo(array $libIndex, ?string $prov, ?string $city, ?string $area): bool
    {
        $prov = trim((string)$prov);
        $city = trim((string)$city);
        $area = trim((string)$area);

        if ($prov === '' || $city === '' || $area === '') return false;
        if (!isset($libIndex[$prov])) return false;
        if (!isset($libIndex[$prov][$city])) return false;

        return in_array($area, $libIndex[$prov][$city], true);
    }

    public function index()
    {
        $lib = $this->loadAddressLibrary();

        return view('jnt.waybills.sender-address', [
            'rows' => SenderAddress::query()->orderByDesc('id')->get(),
            'addressIndex' => $lib['index'], // used by JS for dropdowns
            'txtFound' => File::exists($this->addressTxtPath()),
            'txtPath' => $this->addressTxtPath(),
        ]);
    }

    public function store(Request $request)
    {
        $lib = $this->loadAddressLibrary();

        $data = $request->validate([
            'jnt_sender_phone'   => ['nullable','string','max:50'],
            'jnt_sender_prov'    => ['required','string','max:60'],
            'jnt_sender_city'    => ['required','string','max:100'],
            'jnt_sender_area'    => ['required','string','max:50'],
            'jnt_sender_address' => ['required','string','max:300'],
        ]);

        if (!$this->isValidCombo($lib['index'], $data['jnt_sender_prov'], $data['jnt_sender_city'], $data['jnt_sender_area'])) {
            return back()
                ->withInput()
                ->withErrors([
                    'jnt_sender_area' => 'Invalid PROV|CITY|BRGY combo based on jnt_address.txt (your AREA must match BRGY).',
                ]);
        }

        SenderAddress::query()->create($data);

        return redirect()->route('jnt.sender_address.index')
            ->with('ok', 'Sender address created.');
    }

    public function update(Request $request, SenderAddress $senderAddress)
    {
        $lib = $this->loadAddressLibrary();

        $data = $request->validate([
            'jnt_sender_phone'   => ['nullable','string','max:50'],
            'jnt_sender_prov'    => ['required','string','max:60'],
            'jnt_sender_city'    => ['required','string','max:100'],
            'jnt_sender_area'    => ['required','string','max:50'],
            'jnt_sender_address' => ['required','string','max:300'],
        ]);

        if (!$this->isValidCombo($lib['index'], $data['jnt_sender_prov'], $data['jnt_sender_city'], $data['jnt_sender_area'])) {
            return back()
                ->withInput()
                ->withErrors([
                    'row_'.$senderAddress->id => "Invalid PROV|CITY|BRGY combo for row #{$senderAddress->id} based on jnt_address.txt.",
                ]);
        }

        $senderAddress->update($data);

        return redirect()->route('jnt.sender_address.index')
            ->with('ok', "Row #{$senderAddress->id} updated.");
    }

    public function destroy(SenderAddress $senderAddress)
    {
        $id = $senderAddress->id;
        $senderAddress->delete();

        return redirect()->route('jnt.sender_address.index')
            ->with('ok', "Row #{$id} deleted.");
    }
}
