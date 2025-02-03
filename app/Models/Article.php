<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'author', 'description', 'source', 'category', 'tags', 'url', 'published_at'
    ];
    protected $casts = [
        'tags' => 'array',
    ];
}
