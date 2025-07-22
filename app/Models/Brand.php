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
     * رابطه many-to-many با محصولات از طریق جدول brandables
     */
    public function products(): MorphToMany
    {
        return $this->morphedByMany(
            Product::class,
            'brandables',
            'brandables',
            'brand_id',        // کلید برند در جدول brandables
            'brandables_id'    // کلید محصول در جدول brandables
        );
    }
}
