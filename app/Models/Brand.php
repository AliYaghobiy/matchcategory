<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Brand extends Model
{
    protected $fillable = [
        'name', 'nameSeo', 'slug', 'image', 'body', 'bodySeo', 'keyword'
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
