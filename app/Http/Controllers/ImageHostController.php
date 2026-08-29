<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * /image-host — Simpleng image uploader na nagbabalik ng PUBLIC na image URL.
 * Naka-save sa `storage/app/public/uploads/` (kailangan ng `php artisan storage:link`).
 * Ang view ng image (/storage/uploads/xxx.jpg) ay static file — publicly accessible,
 * walang auth/IP restriction — kaya pwedeng gamitin bilang image_url (hal. sa BotCake).
 */
class ImageHostController extends Controller
{
    private const DIR = 'uploads';

    public function index()
    {
        return view('image_host.index', ['images' => $this->listImages()]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:10240', // 10 MB
        ]);

        $file = $request->file('image');
        $ext  = strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'jpg'));
        $name = date('Ymd_His') . '_' . Str::lower(Str::random(8)) . '.' . $ext;

        $path = $file->storeAs(self::DIR, $name, 'public');

        return response()->json([
            'ok'   => true,
            'url'  => $this->publicUrl($path),
            'name' => $name,
        ]);
    }

    public function destroy(Request $request)
    {
        $request->validate(['name' => 'required|string|max:200']);
        $name = basename($request->input('name')); // iwas path traversal
        Storage::disk('public')->delete(self::DIR . '/' . $name);
        return response()->json(['ok' => true]);
    }

    private function publicUrl(string $path): string
    {
        return url(Storage::disk('public')->url($path));
    }

    private function listImages(): array
    {
        $disk = Storage::disk('public');
        $files = $disk->files(self::DIR);
        // pinakabago muna
        usort($files, fn ($a, $b) => $disk->lastModified($b) <=> $disk->lastModified($a));
        $files = array_slice($files, 0, 200);

        return array_map(fn ($f) => [
            'name' => basename($f),
            'url'  => $this->publicUrl($f),
            'size' => $disk->size($f),
        ], $files);
    }
}
