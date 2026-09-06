<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Upload an image file to local storage.
     *
     * Accepts: image/jpeg, image/png, image/gif, image/webp, image/svg+xml
     * Max size: 10 MB
     * Returns: public URL for use in funnel blocks
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,gif,webp,svg|max:10240',
            'folder' => 'nullable|string|max:100',
        ]);

        $file = $request->file('file');
        $folder = $request->input('folder', 'funnels');

        // Generate unique filename
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $cleanName = Str::slug($originalName);
        $extension = $file->getClientOriginalExtension();
        $filename = $cleanName . '-' . Str::random(8) . '.' . $extension;

        // Store in public disk
        $path = $file->storeAs($folder, $filename, 'public');

        // Build public URL
        $url = config('app.url') . '/storage/' . $path;

        return response()->json([
            'url' => $url,
            'path' => $path,
            'filename' => $filename,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ], 201);
    }

    /**
     * Upload multiple images at once.
     */
    public function storeMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'files' => 'required|array|max:10',
            'files.*' => 'file|mimes:jpeg,png,gif,webp,svg|max:10240',
            'folder' => 'nullable|string|max:100',
        ]);

        $folder = $request->input('folder', 'funnels');
        $results = [];

        foreach ($request->file('files') as $file) {
            $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $cleanName = Str::slug($originalName);
            $extension = $file->getClientOriginalExtension();
            $filename = $cleanName . '-' . Str::random(8) . '.' . $extension;

            $path = $file->storeAs($folder, $filename, 'public');
            $url = config('app.url') . '/storage/' . $path;

            $results[] = [
                'url' => $url,
                'path' => $path,
                'filename' => $filename,
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ];
        }

        return response()->json(['files' => $results], 201);
    }

    /**
     * List uploaded files in a folder.
     */
    public function index(Request $request): JsonResponse
    {
        $folder = $request->input('folder', 'funnels');
        $disk = \Storage::disk('public');
        $directory = $folder . '/';

        if (! $disk->exists($directory)) {
            return response()->json(['files' => []]);
        }

        $files = $disk->files($directory);
        $results = array_map(function ($path) use ($disk) {
            return [
                'url' => config('app.url') . '/storage/' . $path,
                'path' => $path,
                'filename' => basename($path),
                'size' => $disk->size($path),
                'lastModified' => date('c', $disk->lastModified($path)),
            ];
        }, $files);

        return response()->json(['files' => $results]);
    }

    /**
     * Delete an uploaded file.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');
        $disk = \Storage::disk('public');

        if (! $disk->exists($path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $disk->delete($path);

        return response()->json(['message' => 'File deleted', 'path' => $path]);
    }
}
