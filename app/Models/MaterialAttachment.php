<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MaterialAttachment extends Model
{
    protected $hidden = ['stored_path', 'storage_disk', 'sha256', 'thumbnail_path', 'primary_material_id'];

    protected $fillable = [
        'uuid', 'material_id', 'original_filename', 'stored_path', 'storage_disk', 'mime_type',
        'file_size', 'sha256', 'display_order', 'is_primary', 'primary_material_id', 'thumbnail_path',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'display_order' => 'integer', 'is_primary' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attachment): void {
            $attachment->uuid ??= (string) Str::uuid();
        });
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
