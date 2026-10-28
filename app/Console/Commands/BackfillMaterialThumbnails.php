<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Services\MaterialThumbnailService;
use Illuminate\Console\Command;

class BackfillMaterialThumbnails extends Command
{
    protected $signature = 'bayani:backfill-thumbnails';

    protected $description = 'Generate private, content-derived thumbnails for stored educational resources';

    public function handle(MaterialThumbnailService $thumbnails): int
    {
        $generated = 0;
        $reused = 0;
        $sourceImages = 0;
        $downloadOnly = 0;
        $failed = [];

        $skipped = 0;
        Material::withTrashed()->whereNotNull('file_path')->orderBy('id')->chunkById(50, function ($materials) use ($thumbnails, &$generated, &$reused, &$sourceImages, &$downloadOnly, &$failed, &$skipped): void {
            foreach ($materials as $material) {
                try {
                    $result = $thumbnails->generate($material);
                    if ($result === null) {
                        $downloadOnly++;
                    } elseif ($result['source'] ?? false) {
                        $sourceImages++;
                    } elseif ($result['reused'] ?? false) {
                        $reused++;
                    } else {
                        $generated++;
                    }
                } catch (\Throwable $exception) {
                    $skipped++;
                    $failed[] = [$material->id, $material->original_filename ?: $material->file_path, $exception->getMessage()];
                }
            }
        });

        $this->table(['Result', 'Count'], [
            ['Content thumbnails regenerated', $generated],
            ['Content thumbnails reused', $reused],
            ['Original image previews ready', $sourceImages],
            ['PPTX download-only', $downloadOnly],
            ['Skipped due to rendering failure or unsupported content', $skipped],
            ['Failed', count($failed)],
        ]);
        if ($failed) {
            $this->table(['Material ID', 'Attachment', 'Issue'], $failed);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
