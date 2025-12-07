<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Domain model for storing crawlable domains.
 *
 * @property int $id
 * @property string $domain
 * @property array|null $allowed_subdomains
 * @property array|null $crawl_settings
 * @property bool $is_active
 * @property \Carbon\Carbon|null $last_crawled_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Page> $pages
 */
class Domain extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'allowed_subdomains',
        'crawl_settings',
        'is_active',
        'last_crawled_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_subdomains' => 'array',
            'crawl_settings' => 'array',
            'is_active' => 'boolean',
            'last_crawled_at' => 'datetime',
        ];
    }

    /**
     * Get all pages for this domain.
     *
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * Scope to get only active domains.
     *
     * @param \Illuminate\Database\Eloquent\Builder<Domain> $query
     * @return \Illuminate\Database\Eloquent\Builder<Domain>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if a given URL belongs to this domain (including allowed subdomains).
     */
    public function isUrlAllowed(string $url): bool
    {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';

        // Check main domain
        if ($host === $this->domain || str_ends_with($host, '.' . $this->domain)) {
            // If no subdomains are specified, allow all
            if (empty($this->allowed_subdomains)) {
                return true;
            }

            // Check if subdomain is in allowed list
            foreach ($this->allowed_subdomains as $subdomain) {
                if ($host === $subdomain . '.' . $this->domain || $host === $this->domain) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the base URL for this domain.
     */
    public function getBaseUrl(): string
    {
        $protocol = $this->crawl_settings['protocol'] ?? 'https';
        return "{$protocol}://{$this->domain}";
    }
}

