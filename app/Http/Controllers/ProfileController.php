<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'organization' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:400'],
            'profile_image' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp'],
        ], ['profile_image.max' => 'Profile picture must not exceed 10 MB.']);

        if ($request->hasFile('profile_image')) {
            $file = $request->file('profile_image');
            $image = @getimagesize($file->getRealPath());
            $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
            if (!$image || !in_array($image[2] ?? null, $allowed, true)) {
                throw ValidationException::withMessages(['profile_image' => 'Choose a valid PNG, JPG/JPEG, or WEBP image.']);
            }
            if ($image[0] > 10000 || $image[1] > 10000 || ($image[0] * $image[1]) > 40000000) {
                throw ValidationException::withMessages(['profile_image' => 'This image has excessive dimensions. Choose an image no larger than 10,000 pixels per side and 40 megapixels total.']);
            }

            $source = match ($image[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
                IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file->getRealPath()) : false,
            };
            if (!$source) {
                throw ValidationException::withMessages(['profile_image' => 'This image could not be safely decoded. Try another PNG, JPG/JPEG, or WEBP file.']);
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, 1024 / max($width, $height));
            $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
            imagedestroy($source);

            $extension = match ($image[2]) { IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp' };
            $path = 'profile-images/'.Str::uuid().'.'.$extension;
            $stream = fopen('php://temp', 'w+b');
            $encoded = match ($image[2]) {
                IMAGETYPE_JPEG => imagejpeg($target, $stream, 85),
                IMAGETYPE_PNG => imagepng($target, $stream, 6),
                IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($target, $stream, 82) : false,
            };
            imagedestroy($target);
            if (!$encoded) {
                fclose($stream);
                throw ValidationException::withMessages(['profile_image' => 'This image format cannot be optimized on the server. Choose PNG, JPG/JPEG, or WEBP.']);
            }
            rewind($stream);
            try {
                if (!Storage::disk('bayani_uploads')->put($path, $stream)) {
                    throw new \RuntimeException('The profile image could not be written.');
                }
            } catch (\Throwable $exception) {
                fclose($stream);
                throw ValidationException::withMessages(['profile_image' => 'The profile image could not be stored. Please retry.']);
            }
            fclose($stream);

            $validated['profile_image'] = $path;
        }

        $user->fill(collect($validated)->except('profile_image')->all());
        if (isset($validated['profile_image'])) {
            $user->profile_image = $validated['profile_image'];
        }
        $user->save();

        return back()->with('status', 'Profile updated.');
    }

    public function avatar(Request $request): StreamedResponse
    {
        $path = $request->user()->profile_image;
        abort_unless(is_string($path) && str_starts_with($path, 'profile-images/') && !str_contains($path, '..'), 404);

        $privateDisk = Storage::disk('bayani_uploads');
        $disk = $privateDisk->exists($path) ? $privateDisk : Storage::disk('public');
        abort_unless($disk->exists($path), 404);
        $mime = $disk->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        if ($disk !== $privateDisk) {
            $legacyStream = $disk->readStream($path);
            abort_unless(is_resource($legacyStream), 404);
            try {
                if ($privateDisk->writeStream($path, $legacyStream)) {
                    $disk = $privateDisk;
                }
            } finally {
                fclose($legacyStream);
            }
        }
        $stream = $disk->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => '"'.hash('sha256', $path).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Cookie',
        ]);
    }
}
