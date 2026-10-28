<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Subject extends Model { protected $guarded=[]; public function curricula(): BelongsToMany { return $this->belongsToMany(Curriculum::class,'curriculum_subjects')->withPivot(['grade_level_id','classification','school_year','status'])->withTimestamps(); } }
