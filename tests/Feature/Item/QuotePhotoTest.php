<?php

namespace Tests\Feature\Item;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Photo ng supplier quote: jpg/jpeg/png/webp ≤10 MB, generated name, public disk,
 * supplier-quote-images/. Walang photo sa request = hindi ginagalaw ang dating photo.
 */
class QuotePhotoTest extends ItemTestCase
{
    /** Totoong 1×1 PNG (hindi kailangan ng GD). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplierId = DB::table('suppliers')->insertGetId(['name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * TOTOONG uploaded file (hindi UploadedFile::fake()): ang fake ay nagre-report ng mime
     * mula sa file name, kaya hindi nito nasusubok ang content sniffing (finfo) ng `image` rule.
     */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qph');
        file_put_contents($path, $content);
        return new UploadedFile($path, $name, null, null, true);
    }

    private function png(string $name = 'kuha.png', int $padKb = 0): UploadedFile
    {
        return $this->upload($name, base64_decode(self::PNG) . str_repeat("\0", $padKb * 1024));
    }

    private function save(array $fields)
    {
        return $this->post('/item/quotes', array_merge([
            'item_name' => '1 x HAND GRIP', 'supplier_id' => $this->supplierId, 'price' => 100,
        ], $fields), ['Accept' => 'application/json']);
    }

    private function photoPath(): ?string
    {
        return DB::table('item_supplier_quotes')->where('supplier_id', $this->supplierId)->value('photo_path');
    }

    private function storedFiles(): array
    {
        return Storage::disk('public')->allFiles();
    }

    public function test_stores_the_photo_under_a_generated_name_and_returns_its_url(): void
    {
        $this->actingAs($this->user());
        // Minimal na JPEG header (FFD8FF … FFD9) — kinikilala ng finfo bilang image/jpeg.
        $jpg = $this->upload('kuha.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");

        $json = $this->save(['photo' => $jpg])->assertOk()->json();

        $path = $this->photoPath();
        $this->assertStringStartsWith('supplier-quote-images/', $path);
        $this->assertNotSame('supplier-quote-images/kuha.jpg', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('/storage/' . $path, $json['quotes'][0]['photo_url']);
    }

    public function test_photo_lifecycle_keep_replace_delete(): void
    {
        $this->actingAs($this->user());
        // Mga file na HINDI dapat magalaw: item photo + photo ng quote ng ibang supplier.
        Storage::disk('public')->put('item-images/hand-grip.jpg', 'x');
        $betaId = DB::table('suppliers')->insertGetId(['name' => 'Beta', 'created_at' => now(), 'updated_at' => now()]);
        $this->save(['supplier_id' => $betaId, 'photo' => $this->png('beta.png')])->assertOk();
        $betaPhoto = DB::table('item_supplier_quotes')->where('supplier_id', $betaId)->value('photo_path');
        $untouched = ['item-images/hand-grip.jpg', $betaPhoto];

        $this->save(['photo' => $this->png()])->assertOk();
        $first = $this->photoPath();

        // Walang photo (JSON save, gaya ng /item/photo) + pekeng photo_path → hindi nagbabago
        $this->postJson('/item/quotes', [
            'item_name' => '1 x HAND GRIP', 'supplier_id' => $this->supplierId, 'price' => 120,
            'photo_path' => 'item-images/iba.jpg',
        ])->assertOk();
        $this->assertSame($first, $this->photoPath());
        Storage::disk('public')->assertExists($first);

        // Bagong photo → palit, tanggal ang luma
        $this->save(['photo' => $this->png('bago.png')])->assertOk();
        $second = $this->photoPath();
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        $this->assertEqualsCanonicalizing(array_merge($untouched, [$second]), $this->storedFiles());

        // Delete ng quote → tanggal din ang photo (pero hindi ang iba)
        $id = (int) DB::table('item_supplier_quotes')->where('supplier_id', $this->supplierId)->value('id');
        $this->postJson('/item/quotes/delete', ['id' => $id, 'item_name' => '1 x HAND GRIP'])->assertOk();
        $this->assertEqualsCanonicalizing($untouched, $this->storedFiles());
    }

    public function test_photo_path_from_the_request_is_ignored_on_create(): void
    {
        $this->actingAs($this->user());
        $this->postJson('/item/quotes', [
            'item_name' => '1 x HAND GRIP', 'supplier_id' => $this->supplierId, 'photo_path' => 'item-images/iba.jpg',
        ])->assertOk();

        $this->assertNull($this->photoPath());
    }

    public function test_rejects_bad_uploads_and_stores_nothing(): void
    {
        $this->actingAs($this->user());
        $cases = [
            'hindi image'           => $this->upload('notes.txt', 'hello'),
            'pekeng .jpg'           => $this->upload('kuha.jpg', '<?php echo 1; ?>'),
            'SVG'                   => $this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'lampas 10 MB'          => $this->png('malaki.png', 10241),
        ];
        foreach ($cases as $label => $file) {
            $this->assertSame(422, $this->save(['photo' => $file])->status(), $label);
            $this->assertSame([], $this->storedFiles(), $label);
            $this->assertSame(0, DB::table('item_supplier_quotes')->count(), $label);
        }
    }

    public function test_non_ceo_cannot_upload(): void
    {
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->save(['photo' => $this->png()])->assertStatus(403);
        $this->assertSame([], $this->storedFiles());
    }
}
