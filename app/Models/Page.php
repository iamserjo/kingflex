<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Page model for storing crawled pages.
 *
 * @property int $id
 * @property int $domain_id
 * @property string $url
 * @property string $url_hash
 * @property string|null $title
 * @property string|null $summary
 * @property array|null $keywords
 * @property string|null $page_type
 * @property array|null $metadata
 * @property int $depth
 * @property int $inbound_links_count
 * @property \Carbon\Carbon|null $last_crawled_at
 * @property string|null $raw_html
 * @property array|null $embedding
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Domain $domain
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PageLink> $inboundLinks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PageLink> $outboundLinks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PageScreenshot> $screenshots
 * @property-read Product|null $product
 * @property-read Article|null $article
 * @property-read Contact|null $contact
 * @property-read Category|null $category
 */
class Page extends Model
{
    use HasFactory;

    public const TYPE_PRODUCT = 'product';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_ARTICLE = 'article';
    public const TYPE_HOMEPAGE = 'homepage';
    public const TYPE_CONTACT = 'contact';
    public const TYPE_OTHER = 'other';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'domain_id',
        'url',
        'url_hash',
        'title',
        'summary',
        'keywords',
        'page_type',
        'metadata',
        'depth',
        'inbound_links_count',
        'last_crawled_at',
        'raw_html',
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
            'keywords' => 'array',
            'metadata' => 'array',
            'depth' => 'integer',
            'inbound_links_count' => 'integer',
            'last_crawled_at' => 'datetime',
            'embedding' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Page $page) {
            if (empty($page->url_hash)) {
                $page->url_hash = hash('sha256', $page->url);
            }
        });
    }

    /**
     * Get the domain this page belongs to.
     *
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Get pages that link TO this page.
     *
     * @return HasMany<PageLink, $this>
     */
    public function inboundLinks(): HasMany
    {
        return $this->hasMany(PageLink::class, 'target_page_id');
    }

    /**
     * Get pages that this page links TO.
     *
     * @return HasMany<PageLink, $this>
     */
    public function outboundLinks(): HasMany
    {
        return $this->hasMany(PageLink::class, 'source_page_id');
    }

    /**
     * Get screenshots for this page.
     *
     * @return HasMany<PageScreenshot, $this>
     */
    public function screenshots(): HasMany
    {
        return $this->hasMany(PageScreenshot::class);
    }

    /**
     * Get the latest screenshot for this page.
     *
     * @return HasOne<PageScreenshot, $this>
     */
    public function latestScreenshot(): HasOne
    {
        return $this->hasOne(PageScreenshot::class)->latestOfMany();
    }

    /**
     * Get the product data if this is a product page.
     *
     * @return HasOne<Product, $this>
     */
    public function product(): HasOne
    {
        return $this->hasOne(Product::class);
    }

    /**
     * Get the article data if this is an article page.
     *
     * @return HasOne<Article, $this>
     */
    public function article(): HasOne
    {
        return $this->hasOne(Article::class);
    }

    /**
     * Get the contact data if this is a contact page.
     *
     * @return HasOne<Contact, $this>
     */
    public function contact(): HasOne
    {
        return $this->hasOne(Contact::class);
    }

    /**
     * Get the category data if this is a category page.
     *
     * @return HasOne<Category, $this>
     */
    public function category(): HasOne
    {
        return $this->hasOne(Category::class);
    }

    /**
     * Scope to get pages that need recrawling based on priority.
     *
     * @param \Illuminate\Database\Eloquent\Builder<Page> $query
     * @return \Illuminate\Database\Eloquent\Builder<Page>
     */
    public function scopeNeedsRecrawl($query)
    {
        $intervals = config('crawler.recrawl_intervals');

        return $query->where(function ($q) use ($intervals) {
            // Never crawled pages first
            $q->whereNull('last_crawled_at')
                // High priority: 100+ links, 1 hour interval
                ->orWhere(function ($q) use ($intervals) {
                    $q->where('inbound_links_count', '>=', $intervals['high_priority']['min_links'])
                        ->where('last_crawled_at', '<', now()->subHours($intervals['high_priority']['interval_hours']));
                })
                // Medium priority: 10-99 links, 6 hour interval
                ->orWhere(function ($q) use ($intervals) {
                    $q->where('inbound_links_count', '>=', $intervals['medium_priority']['min_links'])
                        ->where('inbound_links_count', '<', $intervals['high_priority']['min_links'])
                        ->where('last_crawled_at', '<', now()->subHours($intervals['medium_priority']['interval_hours']));
                })
                // Low priority: <10 links, 24 hour interval
                ->orWhere(function ($q) use ($intervals) {
                    $q->where('inbound_links_count', '<', $intervals['medium_priority']['min_links'])
                        ->where('last_crawled_at', '<', now()->subHours($intervals['low_priority']['interval_hours']));
                });
        })->orderByRaw('
            CASE
                WHEN last_crawled_at IS NULL THEN 0
                WHEN inbound_links_count >= ? THEN 1
                WHEN inbound_links_count >= ? THEN 2
                ELSE 3
            END,
            last_crawled_at ASC NULLS FIRST
        ', [$intervals['high_priority']['min_links'], $intervals['medium_priority']['min_links']]);
    }

    /**
     * Calculate the recrawl interval for this page in hours.
     */
    public function getRecrawlIntervalHours(): int
    {
        $intervals = config('crawler.recrawl_intervals');

        if ($this->inbound_links_count >= $intervals['high_priority']['min_links']) {
            return $intervals['high_priority']['interval_hours'];
        }

        if ($this->inbound_links_count >= $intervals['medium_priority']['min_links']) {
            return $intervals['medium_priority']['interval_hours'];
        }

        return $intervals['low_priority']['interval_hours'];
    }

    /**
     * Check if this page needs to be recrawled.
     */
    public function needsRecrawl(): bool
    {
        if ($this->last_crawled_at === null) {
            return true;
        }

        $intervalHours = $this->getRecrawlIntervalHours();

        return $this->last_crawled_at->addHours($intervalHours)->isPast();
    }

    /**
     * Update the inbound links count.
     */
    public function updateInboundLinksCount(): void
    {
        $this->inbound_links_count = $this->inboundLinks()->count();
        $this->save();
    }

    /**
     * Find similar pages using vector similarity search.
     *
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection<int, Page>
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

