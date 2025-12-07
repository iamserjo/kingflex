<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Content tag extracted from page by AI.
 * Represents what the page is actually about.
 *
 * @property int $id
 * @property int $page_id
 * @property string $tag
 * @property int $weight
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Page $page
 */
class PageContentTag extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'page_id',
        'tag',
        'weight',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
        ];
    }

    /**
     * Get the page this tag belongs to.
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Scope to get tags ordered by weight.
     *
     * @param \Illuminate\Database\Eloquent\Builder<PageContentTag> $query
     * @return \Illuminate\Database\Eloquent\Builder<PageContentTag>
     */
    public function scopeByWeight($query)
    {
        return $query->orderByDesc('weight');
    }

    /**
     * Scope to filter by minimum weight.
     *
     * @param \Illuminate\Database\Eloquent\Builder<PageContentTag> $query
     * @param int $minWeight
     * @return \Illuminate\Database\Eloquent\Builder<PageContentTag>
     */
    public function scopeMinWeight($query, int $minWeight)
    {
        return $query->where('weight', '>=', $minWeight);
    }
}

