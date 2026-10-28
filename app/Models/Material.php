<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Material extends Model
{
    use SoftDeletes;

    protected $appends = ['has_file'];

    protected $hidden = ['file_path', 'file_disk', 'file_sha256', 'thumbnail_path'];

    public const STATUSES = ['Draft', 'Pending', 'Approved', 'Revision Requested', 'Rejected', 'Archived'];

    public const STATUS_TRANSITIONS = [
        'Draft' => ['Pending', 'Archived'],
        'Pending' => ['Approved', 'Rejected', 'Revision Requested', 'Archived'],
        'Rejected' => ['Pending', 'Archived'],
        'Revision Requested' => ['Pending', 'Archived'],
        'Approved' => ['Pending', 'Archived'],
        'Archived' => [],
    ];

    protected $fillable = [
        'title', 'description', 'topic', 'keywords', 'category_id', 'curriculum_id', 'material_type_id', 'track_id', 'strand_id',
        'contributor_id', 'status', 'file_path', 'file_disk', 'original_filename', 'file_type',
        'file_size', 'file_sha256', 'thumbnail_path', 'submitted_at', 'published_at',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'submitted_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::STATUS_TRANSITIONS[$this->status] ?? [], true);
    }

    public function getHasFileAttribute(): bool
    {
        return (bool) $this->file_path;
    }

    public function contributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contributor_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function curriculum(): BelongsTo { return $this->belongsTo(Curriculum::class); }
    public function materialType(): BelongsTo { return $this->belongsTo(MaterialType::class); }
    public function track(): BelongsTo { return $this->belongsTo(Track::class); }
    public function strand(): BelongsTo { return $this->belongsTo(Strand::class); }
    public function subjects(): BelongsToMany { return $this->belongsToMany(Subject::class)->withTimestamps(); }
    public function gradeLevels(): BelongsToMany { return $this->belongsToMany(GradeLevel::class)->withTimestamps(); }

    public function reviews(): HasMany
    {
        return $this->hasMany(MaterialReview::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MaterialAttachment::class)->orderBy('display_order')->orderBy('id');
    }

    public function primaryAttachment(): HasOne
    {
        return $this->hasOne(MaterialAttachment::class)->where('is_primary', true);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }
}
