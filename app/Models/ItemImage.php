<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemImage extends Model
{
    protected $table = 'item_images';

    protected $fillable = ['item_name', 'image_path', 'updated_by'];
}
