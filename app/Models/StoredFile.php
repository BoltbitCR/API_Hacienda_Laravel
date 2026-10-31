<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoredFile extends Model
{
    protected $table = 'stored_files';

    protected $guarded = [];

    protected $casts = ['encrypted' => 'boolean'];
}
