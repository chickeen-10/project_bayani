<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Curriculum extends Model { protected $guarded=[]; public function subjects(): BelongsToMany { return $this->belongsToMany(Subject::class,'curriculum_subjects')->withPivot(['grade_level_id','classification','school_year','status'])->withTimestamps(); } }
