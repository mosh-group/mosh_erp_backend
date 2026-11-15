<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FileLink extends Model { public $timestamps=false; protected $guarded=[]; public function file() { return $this->belongsTo(StoredFile::class,'file_id'); } }
