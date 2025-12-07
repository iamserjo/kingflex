<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product model for storing product data extracted from pages.
 *
 * @property int $id
 * @property int $page_id
 * @property string $name
 * @property float|null $price
 * @property string|null $currency
 * @property string|null $description
 * @property array|null $images
 * @property array|null $attributes
 * @property string|null $sku
 * @property string|null $availability
 * @property array|null $embedding
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Page $page
 */
class Product extends Model
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
        'price',
        'currency',
        'description',
        'images',
        'attributes',
        'sku',
        'availability',
        'embedding',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'images' => 'array',
            'attributes' => 'array',
            'embedding' => 'array',
        ];
    }

    /**
     * Get the page this product was extracted from.
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the formatted price with currency.
     */
    public function getFormattedPrice(): ?string
    {
        if ($this->price === null) {
            return null;
        }

        return number_format($this->price, 2) . ' ' . ($this->currency ?? 'USD');
    }

    /**
     * Find similar products using vector similarity search.
     *
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function findSimilar(int $limit = 10)
    {
        if (empty($this->embedding)) {
            return collect();
        }

        $embeddingString = '[' . implode(',', $this->embedding) . ']';

        return static::query()
            ->where('id', '!=', $this->id)
            ->whereNotNull('embedding')
            ->selectRaw('*, embedding <=> ? as distance', [$embeddingString])
            ->orderBy('distance')
            ->limit($limit)
            ->get();
    }
}

