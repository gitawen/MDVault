<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaOperationException;
use App\Http\Requests\Media\StoreVaultMediaRequest;
use App\Models\Vault;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    /**
     * Validate and store an attached raster image in the shared Media folder.
     *
     * @throws ValidationException
     */
    public function store(StoreVaultMediaRequest $request, Vault $vault, MediaService $media): JsonResponse
    {
        $file = $request->file('image');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'image' => MediaOperationException::invalidImage()->getMessage(),
            ]);
        }

        try {
            $stored = $media->store($vault, $file);
        } catch (MediaOperationException $e) {
            throw ValidationException::withMessages([
                $e->field() => $e->getMessage(),
            ]);
        }

        return response()->json($stored, 201);
    }

    /**
     * Serve a validated raster image inline from the shared Media folder.
     */
    public function show(string $filename, MediaService $media): BinaryFileResponse
    {
        $resolved = $media->resolveForServing($filename);

        if ($resolved === null) {
            abort(404);
        }

        return response()->file($resolved['path'], [
            'Content-Type' => $resolved['mime'],
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
