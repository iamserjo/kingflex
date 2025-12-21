<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Centralized selection logic for "page attributes extraction" candidates.
 *
 * Keep this in sync with the business rules used by the artisan command:
 * `php artisan page:extract-attributes`.
 */
final class PageAttributesCandidateService
{
    /**
     * Build the base query for pages eligible for attributes extraction.
     *
     * @return Builder<Page>
     */
    public function eligibleQuery(?string $domain = null): Builder
    {
        /** @var Builder<Page> $query */
        $query = Page::query()
            ->where('is_product', true)
            ->where('is_product_available', true)
            ->whereNotNull('content_with_tags_purified')
            ->whereNotNull('product_type_id')
            ->orderBy('id');

        if ($domain) {
            $query->whereHas('domain', fn (Builder $q) => $q->where('domain', $domain));
        }

        return $query;
    }

    /**
     * Get next page candidate after a given id.
     */
    public function nextCandidate(int $afterId, ?string $domain = null, bool $force = false): ?Page
    {
        $query = $this->eligibleQuery(domain: $domain)->where('id', '>', $afterId);

        if (!$force) {
            $query->whereNull('attributes_extracted_at');
        }

        return $query->first();
    }

    /**
     * Get N "pending" candidates to be processed next (oldest first).
     *
     * @return Collection<int, Page>
     */
    public function pending(int $limit = 5, ?string $domain = null): Collection
    {
        return $this->eligibleQuery(domain: $domain)
            ->whereNull('attributes_extracted_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Get already processed product pages (attributes extracted).
     */
    public function processed(int $perPage = 50, ?string $domain = null): LengthAwarePaginator
    {
        return Page::query()
            ->where('is_product', true)
            ->where('is_product_available', true)
            ->whereNotNull('product_type_id')
            ->whereNotNull('json_attributes')
            ->when($domain, fn (Builder $q) => $q->whereHas('domain', fn (Builder $qq) => $qq->where('domain', $domain)))
            ->orderByDesc('id')
            ->paginate(max(1, $perPage));
    }
}

