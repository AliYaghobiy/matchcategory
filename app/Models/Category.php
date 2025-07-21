<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Category extends Model
{
    protected $fillable = [
        'name', 'nameSeo', 'type', 'bodySeo',
        'keyword', 'body', 'image', 'slug'
    ];

    /**
     * رابطه many-to-many با محصولات از طریق جدول catables
     */
    public function products(): MorphToMany
    {
        return $this->morphedByMany(
            Product::class,
            'catables',
            'catables',
            'category_id',
            'catables_id'
        );
    }
}
