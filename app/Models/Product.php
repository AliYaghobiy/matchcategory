<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
    public function brands(): BelongsToMany
    {
        return $this->morphToMany(
            Brand::class,
            'catables',
            'catables',
            'catables_id',
            'category_id'
        );
    }
}
