<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MaterialThumbnailService
{
    public function __construct(private readonly OpenXmlPreviewService $openXml) {}

    /** Return a private, content-derived thumbnail path and MIME type. */
    public function generate(Material|MaterialAttachment $material): ?array
    {
        $attachment = $material instanceof MaterialAttachment;
        $filename = $attachment ? $material->original_filename : $material->original_filename;
        $filePath = $attachment ? $material->stored_path : $material->file_path;
        $extension = strtolower(pathinfo($filename ?: $filePath ?: '', PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return ['path' => $filePath, 'mime' => match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', default => 'image/webp',
            }, 'source' => true];
        }
        if (in_array($extension, ['mp3','wav','ogg','pptx'], true)) {
            return null;
        }
        if (in_array($extension, ['mp4', 'webm'], true)) {
            $disk = $attachment ? $this->attachmentDisk($material) : $this->disk($material);
            $storage = Storage::disk($disk);
            $fingerprint = $attachment ? $material->sha256 : $material->file_sha256;
            if (!$fingerprint) {
                $path = $attachment ? $material->stored_path : $material->file_path;
                if ($path && $storage->exists($path)) $fingerprint = hash_file('sha256', $storage->path($path));
            }
            if (!$fingerprint) return null;
            $relativePath = $this->browserCachePath($material, $attachment, $fingerprint, true);
            if ($storage->exists($relativePath)) {
                $info = @getimagesizefromstring($storage->get($relativePath));
                if ($info && ($info['mime'] ?? '') === 'image/jpeg') return ['path' => $relativePath, 'mime' => 'image/jpeg', 'source' => false, 'reused' => true];
                $storage->delete($relativePath);
            }
            return null;
        }
        if (in_array($extension, ['xlsx', 'docx'], true)) {
            $disk = $attachment ? $this->attachmentDisk($material) : $this->disk($material);
            $storage = Storage::disk($disk);
            $path = $attachment ? $material->stored_path : $material->file_path;
            if (!$path || !$storage->exists($path)) throw new \RuntimeException('The source attachment is missing.');
            if ((int) $storage->size($path) > 20 * 1024 * 1024) throw new \RuntimeException('The source attachment exceeds the thumbnail processing limit.');
            $sourcePath = $storage->path($path);
            $fingerprint = $attachment ? ($material->sha256 ?: hash_file('sha256', $sourcePath)) : ($material->file_sha256 ?: hash_file('sha256', $sourcePath));
            if (!$fingerprint) throw new \RuntimeException('The source attachment could not be fingerprinted.');

            $relativePath = $this->browserCachePath($material, $attachment, $fingerprint);
            if ($storage->exists($relativePath)) {
                $info = @getimagesizefromstring($storage->get($relativePath));
                if ($info && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return ['path' => $relativePath, 'mime' => $info['mime'], 'source' => false, 'reused' => true];
                }
                $storage->delete($relativePath);
            }
            $relativePath = 'thumbnails/openxml-'.($attachment ? 'attachment-' : 'material-').(int) $material->getKey().'-'.hash('sha256', strtolower($fingerprint).'|bayani-openxml-content-v1').'.svg';
            if ($material->thumbnail_path !== $relativePath) $this->invalidateTextSnapshotThumbnail($material, $attachment);
            if ($storage->exists($relativePath)) {
                $cached = $storage->get($relativePath);
                if (str_starts_with($cached, '<svg xmlns="http://www.w3.org/2000/svg"') && strlen($cached) <= 512 * 1024 && !str_contains(strtolower($cached), '<script')) {
                    $this->rememberThumbnailPath($material, $relativePath, $storage);
                    return ['path' => $relativePath, 'mime' => 'image/svg+xml', 'source' => false, 'reused' => true];
                }
                $storage->delete($relativePath);
            }
            $preview = $extension === 'xlsx' ? $this->openXml->spreadsheet($sourcePath) : $this->openXml->document($sourcePath);
            $contents = $this->openXml->thumbnailSvg($extension, $preview, $filename ?: 'Educational resource');
            if (strlen($contents) > 512 * 1024 || !str_starts_with($contents, '<svg xmlns="http://www.w3.org/2000/svg"') || str_contains(strtolower($contents), '<script')) {
                throw new \RuntimeException('The generated Office thumbnail failed validation.');
            }
            $storage->put($relativePath, $contents, ['visibility' => 'private']);
            $this->rememberThumbnailPath($material, $relativePath, $storage);
            return ['path' => $relativePath, 'mime' => 'image/svg+xml', 'source' => false, 'reused' => false];
        }
        if ($extension !== 'pdf' || !$filePath) {
            throw new \RuntimeException('This attachment format cannot produce a content thumbnail.');
        }

        $disk = $attachment ? $this->attachmentDisk($material) : $this->disk($material);
        $storage = Storage::disk($disk);
        if (!$storage->exists($filePath)) {
            throw new \RuntimeException('The source attachment is missing.');
        }
        if ((int) $storage->size($filePath) > 20 * 1024 * 1024) {
            throw new \RuntimeException('The source attachment exceeds the preview processing limit.');
        }

        $sourcePath = $storage->path($filePath);
        $fingerprint = $attachment ? ($material->sha256 ?: hash_file('sha256', $sourcePath)) : ($material->file_sha256 ?: hash_file('sha256', $sourcePath));
        if (!$fingerprint) {
            throw new \RuntimeException('The source attachment could not be fingerprinted.');
        }
        $outputExtension = 'png';
        // Bump the version when thumbnail rendering changes so old generic covers cannot survive in cache.
        $rendererVersion = 'bayani-preview-v4';
        $relativePath = 'thumbnails/'.hash('sha256', $fingerprint.'|'.$rendererVersion.'|'.$extension).'.'.$outputExtension;

        $reused = $storage->exists($relativePath);
        if (!$reused) {
            $contents = $this->renderPdf($sourcePath);
            if (strlen($contents) > 4 * 1024 * 1024) {
                throw new \RuntimeException('The generated thumbnail exceeds the cache limit.');
            }
            $storage->put($relativePath, $contents);
        }

        $oldPath = $material->thumbnail_path;
        if ($oldPath !== $relativePath) {
            if ($attachment) $material->forceFill(['thumbnail_path' => $relativePath])->saveQuietly();
            else $material->forceFill(['thumbnail_path' => $relativePath])->saveQuietly();
            if (is_string($oldPath) && str_starts_with($oldPath, 'thumbnails/') && !str_contains($oldPath, '..')
                && !DB::table('materials')->where('thumbnail_path', $oldPath)->exists()
                && !DB::table('material_attachments')->where('thumbnail_path', $oldPath)->exists()) {
                $storage->delete($oldPath);
            }
        }

        return ['path' => $relativePath, 'mime' => $outputExtension === 'png' ? 'image/png' : 'image/svg+xml', 'source' => false, 'reused' => $reused];
    }

    public function storeOfficeCache(Material|MaterialAttachment $source, bool $attachment, string $bytes, string $mime): array
    {
        $filename = $source->original_filename ?: ($attachment ? $source->stored_path : $source->file_path);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, ['docx', 'xlsx', 'mp4', 'webm'], true), 422);
        abort_unless(strlen($bytes) > 0 && strlen($bytes) <= 512 * 1024, 422);
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && ($info['mime'] ?? null) === $mime && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && $info[0] > 0 && $info[1] > 0 && $info[0] <= 900 && $info[1] <= 900, 422);
        abort_unless(function_exists('imagecreatefromstring'), 503);
        $decoded = @imagecreatefromstring($bytes);
        abort_unless($decoded !== false, 422);
        imagedestroy($decoded);

        $disk = $attachment ? $this->attachmentDisk($source) : $this->disk($source);
        $storage = Storage::disk($disk);
        $sourcePath = $attachment ? $source->stored_path : $source->file_path;
        abort_unless($sourcePath && $storage->exists($sourcePath), 404);
        $fingerprint = $attachment ? ($source->sha256 ?: hash_file('sha256', $storage->path($sourcePath))) : ($source->file_sha256 ?: hash_file('sha256', $storage->path($sourcePath)));
        abort_unless(is_string($fingerprint) && preg_match('/^[a-f0-9]{64}$/i', $fingerprint), 422);

        $relativePath = $this->browserCachePath($source, $attachment, $fingerprint, in_array($extension, ['mp4', 'webm'], true));
        $storage->put($relativePath, $bytes, ['visibility' => 'private']);
        $oldPath = $source->thumbnail_path;
        if ($oldPath !== $relativePath) {
            DB::table($attachment ? 'material_attachments' : 'materials')->where('id', $source->getKey())->update(['thumbnail_path' => $relativePath]);
            $source->setAttribute('thumbnail_path', $relativePath);
        }
        if (is_string($oldPath) && str_starts_with($oldPath, 'thumbnails/') && !str_contains($oldPath, '..') && $oldPath !== $relativePath
            && !DB::table('materials')->where('thumbnail_path', $oldPath)->exists()
            && !DB::table('material_attachments')->where('thumbnail_path', $oldPath)->exists()) $storage->delete($oldPath);

        return ['path' => $relativePath, 'mime' => $mime, 'reused' => false];
    }

    private function browserCachePath(Material|MaterialAttachment $source, bool $attachment, string $fingerprint, bool $video = false): string
    {
        $id = (int) $source->getKey();
        return 'thumbnails/'.($video ? 'video-' : 'office-').($attachment ? 'attachment-' : 'material-').$id.'-'.hash('sha256', strtolower($fingerprint).'|browser-capture-v1').'.jpg';
    }

    private function invalidateTextSnapshotThumbnail(Material|MaterialAttachment $material, bool $attachment): void
    {
        $oldPath = $material->thumbnail_path;
        if (!is_string($oldPath) || !str_starts_with($oldPath, 'thumbnails/') || str_contains($oldPath, '..')
            || str_starts_with($oldPath, 'thumbnails/openxml-') || strtolower(pathinfo($oldPath, PATHINFO_EXTENSION)) !== 'svg') {
            return;
        }

        $disk = $attachment ? $this->attachmentDisk($material) : $this->disk($material);
        $material->forceFill(['thumbnail_path' => null])->saveQuietly();
        if (!DB::table('materials')->where('thumbnail_path', $oldPath)->exists()
            && !DB::table('material_attachments')->where('thumbnail_path', $oldPath)->exists()) {
            Storage::disk($disk)->delete($oldPath);
        }
    }

    private function rememberThumbnailPath(Material|MaterialAttachment $source, string $relativePath, $storage): void
    {
        $oldPath = $source->thumbnail_path;
        if ($oldPath === $relativePath) return;
        $source->forceFill(['thumbnail_path' => $relativePath])->saveQuietly();
        if (is_string($oldPath) && str_starts_with($oldPath, 'thumbnails/') && !str_contains($oldPath, '..')
            && !DB::table('materials')->where('thumbnail_path', $oldPath)->exists()
            && !DB::table('material_attachments')->where('thumbnail_path', $oldPath)->exists()) {
            $storage->delete($oldPath);
        }
    }

    private function renderPdf(string $sourcePath): string
    {
        $directory = storage_path('framework/cache/bayani-thumbnails');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The temporary thumbnail directory is unavailable.');
        }
        $temporaryPath = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.png';
        try {
            $process = new Process([
                'node', '--max-old-space-size=256', base_path('scripts/render-pdf-thumbnail.mjs'), $sourcePath, $temporaryPath,
            ]);
            $process->setTimeout(20);
            $process->run();
            if (!$process->isSuccessful() || !is_file($temporaryPath) || filesize($temporaryPath) > 4 * 1024 * 1024) {
                throw new \RuntimeException('The first PDF page could not be rendered safely.');
            }
            $bytes = file_get_contents($temporaryPath);
            if (!is_string($bytes) || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                throw new \RuntimeException('The PDF renderer returned an invalid image.');
            }
            return $bytes;
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function disk(Material $material): string
    {
        return in_array($material->file_disk, ['bayani_uploads', 'local'], true) ? ($material->file_disk ?: 'bayani_uploads') : 'bayani_uploads';
    }

    private function attachmentDisk(MaterialAttachment $attachment): string
    {
        return in_array($attachment->storage_disk, ['bayani_uploads', 'local'], true) ? $attachment->storage_disk : 'bayani_uploads';
    }
}
