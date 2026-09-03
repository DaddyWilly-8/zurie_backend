<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'name',
    'slug',
    'description',
    'short_description',
    'category_id',
    'sku',
    'buying_price',
    'price',
    'sale_price',
    'material',
    'specifications',
    'status',
    'featured',
    'best_seller',
    'new_arrival',
    'seo_title',
    'seo_description',
])]
class Product extends Model
{
    use LogsActivity;

    /**
     * Tracks a summary field set, not every column — description/
     * specifications/seo fields would bloat the stored diff for little
     * audit value. logOnlyDirty() means a create logs the full tracked set
     * but an update only logs what actually changed.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('product')
            ->logOnly(['name', 'slug', 'status', 'price', 'sale_price', 'category_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "Product '{$this->name}' {$event}");
    }

    protected function casts(): array
    {
        return [
            'buying_price' => 'decimal:2',
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'specifications' => 'array',
            'featured' => 'boolean',
            'best_seller' => 'boolean',
            'new_arrival' => 'boolean',
        ];
    }

    /**
     * Same-module FK.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function colors(): HasMany
    {
        return $this->hasMany(ProductColor::class);
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(ProductSize::class);
    }

    /**
     * Active selling price: sale_price if set, otherwise the regular price.
     */
    public function getActivePriceAttribute(): string
    {
        return $this->sale_price ?? $this->price;
    }
}
