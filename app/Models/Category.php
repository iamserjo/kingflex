<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Category model for storing category data extracted from pages.
 *
 * @property int $id
 * @property int $page_id
 * @property string $name
 * @property string|null $description
 * @property string|null $parent_category
 * @property int|null $products_count
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Page $page
 */
class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'page_id',
        'name',
        'description',
        'parent_category',
        'products_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'products_count' => 'integer',
        ];
    }

    /**
     * Get the page this category was extracted from.
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Check if this is a root category (no parent).
     */
    public function isRoot(): bool
    {
        return empty($this->parent_category);
    }
}

