<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article model for storing article data extracted from pages.
 *
 * @property int $id
 * @property int $page_id
 * @property string $title
 * @property string|null $author
 * @property \Carbon\Carbon|null $published_at
 * @property string|null $content
 * @property array|null $tags
 * @property array|null $embedding
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Page $page
 */
class Article extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'page_id',
        'title',
        'author',
        'published_at',
        'content',
        'tags',
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
            'published_at' => 'datetime',
            'tags' => 'array',
            'embedding' => 'array',
        ];
    }

    /**
     * Get the page this article was extracted from.
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get a short excerpt from the content.
     */
    public function getExcerpt(int $length = 200): string
    {
        if (empty($this->content)) {
            return '';
        }

        $content = strip_tags($this->content);

        if (strlen($content) <= $length) {
            return $content;
        }

        return substr($content, 0, $length) . '...';
    }

    /**
     * Find similar articles using vector similarity search.
     *
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection<int, Article>
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

