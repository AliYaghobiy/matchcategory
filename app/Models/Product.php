<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Product extends Model
{
    protected $fillable = [
        'title', 'titleSeo', 'bodySeo', 'price', 'off', 'offPrice',
        'type', 'slug', 'product_id', 'status', 'link', 'receive',
        'count', 'body', 'seen', 'user_id', 'guarantee', 'property',
        'variety', 'imageAlt', 'specifications', 'image', 'keyword'
    ];

    protected $casts = [
        'property' => 'array',
        'variety' => 'array',
        'specifications' => 'array',
        'image' => 'array',
        'keyword' => 'array'
    ];

    /**
     * رابطه many-to-many با دسته‌بندی‌ها از طریق جدول catables
     */
    public function categories(): BelongsToMany
    {
        return $this->morphToMany(
            Category::class,
            'catables',
            'catables',
            'catables_id',
            'category_id'
        );
    }

    /**
     * رابطه many-to-many با برندها از طریق جدول brandables
     */
    public function brands(): MorphToMany
    {
        return $this->morphToMany(
            Brand::class,
            'brandables',
            'brandables',
            'brandables_id',   // کلید محصول در جدول brandables
            'brand_id'         // کلید برند در جدول brandables
        );
    }
}

