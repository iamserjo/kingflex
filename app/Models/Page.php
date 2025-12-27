<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Page model for storing crawled pages.
 *
 * @property int $id
 * @property int $domain_id
 * @property string $url
 * @property string $url_hash
 * @property string|null $title
 * @property string|null $meta_description
 * @property string|null $product_summary
 * @property string|null $product_summary_specs
 * @property string|null $product_abilities
 * @property string|null $product_predicted_search_text
 * @property string|null $recap_content
 * @property array|null $keywords
 * @property string|null $page_type
 * @property array|null $metadata
 * @property int $depth
 * @property int $inbound_links_count
 * @property Carbon|null $last_crawled_at
 * @property Carbon|null $recap_generated_at
 * @property Carbon|null $embedding_generated_at
 * @property string|null $raw_html
 * @property string|null $content_with_tags_purified Rendered content with semantic HTML tags
 * @property string|null $screenshot_path Local storage path (storage/app/...) to latest full-page screenshot
 * @property Carbon|null $screenshot_taken_at Timestamp when screenshot_path was captured
 * @property bool|null $is_product
 * @property bool|null $is_product_available
 * @property bool|null $is_used Product condition: true = used/refurbished, false = new, null = unknown
 * @property int|null $product_type_id
 * @property Carbon|null $product_type_detected_at
 * @property array|null $json_attributes
 * @property string|null $product_code
 * @property string|null $sku
 * @property string|null $product_model_number
 * @property Carbon|null $attributes_extracted_at
 * @property array|null $embedding
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Domain $domain
 * @property-read TypeStructure|null $productType
 * @property-read Collection<int, PageLink> $inboundLinks
 * @property-read Collection<int, PageLink> $outboundLinks
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
        'meta_description',
        'product_summary',
        'product_summary_specs',
        'product_abilities',
        'product_predicted_search_text',
        'recap_content',
        'keywords',
        'page_type',
        'metadata',
        'depth',
        'inbound_links_count',
        'last_crawled_at',
        'recap_generated_at',
        'embedding_generated_at',
        'raw_html',
        'content_with_tags_purified',
        'screenshot_path',
        'screenshot_taken_at',
        'is_product',
        'is_product_available',
        'is_used',
        'product_type_id',
        'product_type_detected_at',
        'json_attributes',
        'product_code',
        'sku',
        'product_model_number',
        'attributes_extracted_at',
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
            'screenshot_taken_at' => 'datetime',
            'recap_generated_at' => 'datetime',
            'embedding_generated_at' => 'datetime',
            'is_product' => 'boolean',
            'is_product_available' => 'boolean',
            'is_used' => 'boolean',
            'product_type_id' => 'integer',
            'product_type_detected_at' => 'datetime',
            'json_attributes' => 'array',
            'attributes_extracted_at' => 'datetime',
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
     * Type structure inferred for this product page.
     *
     * @return BelongsTo<TypeStructure, $this>
     */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(TypeStructure::class, 'product_type_id');
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
     * Get content tags for this page (what the page is about).
     *
     * @return HasMany<PageContentTag, $this>
     */
    public function contentTags(): HasMany
    {
        return $this->hasMany(PageContentTag::class)->orderByDesc('weight');
    }

    /**
     * Get search tags for this page (how users might search for it).
     *
     * @return HasMany<PageSearchTag, $this>
     */
    public function searchTags(): HasMany
    {
        return $this->hasMany(PageSearchTag::class)->orderByDesc('weight');
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
     * Scope to get pages that need recrawling based on priority formula.
     *
     * Formula: effective_age = (now - last_crawled_at) - (inbound_links_count * hours_per_link)
     * Recrawl if: effective_age > max_interval_days AND time_since_crawl >= min_interval_minutes
     *
     * @param \Illuminate\Database\Eloquent\Builder<Page> $query
     * @return \Illuminate\Database\Eloquent\Builder<Page>
     */
    public function scopeNeedsRecrawl($query)
    {
        $minIntervalMinutes = (int) config('crawler.recrawl_priority.min_interval_minutes');
        $maxIntervalDays = (int) config('crawler.recrawl_priority.max_interval_days');
        $hoursPerLink = (int) config('crawler.recrawl_priority.hours_per_link');

        $minIntervalTimestamp = now()->subMinutes($minIntervalMinutes);
        $maxIntervalHours = $maxIntervalDays * 24;

        return $query->where(function ($q) use ($minIntervalTimestamp, $maxIntervalHours, $hoursPerLink) {
            // Never crawled pages (highest priority)
            $q->whereNull('last_crawled_at')
                // Pages that meet the recrawl criteria
                ->orWhereRaw(
                    "EXTRACT(EPOCH FROM (NOW() - last_crawled_at)) / 3600 - (inbound_links_count * ?) > ?
                     AND last_crawled_at < ?",
                    [$hoursPerLink, $maxIntervalHours, $minIntervalTimestamp]
                );
        })->orderByRaw('
            CASE
                WHEN last_crawled_at IS NULL THEN 0
                ELSE EXTRACT(EPOCH FROM (NOW() - last_crawled_at)) / 3600 - (inbound_links_count * ?)
            END DESC
        ', [$hoursPerLink]);
    }

    /**
     * Calculate the effective age of this page in hours.
     * Takes into account the popularity bonus from inbound links.
     */
    public function getEffectiveAgeHours(): float
    {
        if ($this->last_crawled_at === null) {
            return PHP_FLOAT_MAX;
        }

        $hoursPerLink = (int) config('crawler.recrawl_priority.hours_per_link');
        $timeSinceCrawl = now()->diffInHours($this->last_crawled_at);
        $popularityBonus = $this->inbound_links_count * $hoursPerLink;

        return (float) ($timeSinceCrawl - $popularityBonus);
    }

    /**
     * Check if this page needs to be recrawled.
     */
    public function needsRecrawl(): bool
    {
        // Never crawled - always needs crawl
        if ($this->last_crawled_at === null) {
            return true;
        }

        $minIntervalMinutes = (int) config('crawler.recrawl_priority.min_interval_minutes');
        $maxIntervalDays = (int) config('crawler.recrawl_priority.max_interval_days');

        // Check minimum interval (prevent too frequent crawls)
        if ($this->last_crawled_at->copy()->addMinutes($minIntervalMinutes)->isFuture()) {
            return false;
        }

        // Check if effective age exceeds maximum interval
        $effectiveAgeHours = $this->getEffectiveAgeHours();
        $maxIntervalHours = $maxIntervalDays * 24;

        return $effectiveAgeHours > $maxIntervalHours;
    }

    /**
     * Get the next scheduled crawl time for this page.
     */
    public function getNextCrawlTime(): ?Carbon
    {
        if ($this->last_crawled_at === null) {
            return now(); // Crawl ASAP
        }

        $maxIntervalDays = (int) config('crawler.recrawl_priority.max_interval_days');
        $hoursPerLink = (int) config('crawler.recrawl_priority.hours_per_link');

        $popularityBonus = $this->inbound_links_count * $hoursPerLink;
        $adjustedIntervalHours = ($maxIntervalDays * 24) - $popularityBonus;

        // Ensure we don't go below minimum interval
        $minIntervalMinutes = (int) config('crawler.recrawl_priority.min_interval_minutes');
        $adjustedIntervalHours = (float) max($adjustedIntervalHours, $minIntervalMinutes / 60);

        return $this->last_crawled_at->copy()->addHours($adjustedIntervalHours);
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
     * @return Collection<int, Page>
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

