<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Track extends Model { protected $guarded=[]; public function strands(): HasMany { return $this->hasMany(Strand::class); } }
