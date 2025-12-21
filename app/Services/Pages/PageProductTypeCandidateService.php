<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Centralized selection logic for product type detection candidates.
 *
 * Must stay in sync with: `php artisan page:product-type-detect`.
 */
final class PageProductTypeCandidateService
{
    /**
     * Base query for pages that can be analyzed for product type.
     *
     * @return Builder<Page>
     */
    private function baseQuery(int $afterId, ?string $domain = null): Builder
    {
        /** @var Builder<Page> $query */
        $query = Page::query()
            ->where('id', '>', $afterId)
            ->whereNotNull('screenshot_path')
            ->whereNotNull('last_crawled_at')
            ->orderBy('id');

        if ($domain) {
            $query->whereHas('domain', fn (Builder $q) => $q->where('domain', $domain));
        }

        return $query;
    }

    /**
     * Next candidate after given id, applying the same default/backfill rules as the command.
     */
    public function nextCandidate(int $afterId, ?string $domain = null, bool $force = false): ?Page
    {
        $query = $this->baseQuery(afterId: $afterId, domain: $domain);

        if (!$force) {
            $query->where(function (Builder $q) {
                $q->whereNull('product_type_detected_at')
                    ->orWhere(function (Builder $q2) {
                        $q2->where('is_product', true)
                            ->whereNotNull('product_type_detected_at')
                            ->whereNull('product_type_id');
                    })
                    // Backfill obvious filter/listing URLs that were previously misclassified as products.
                    ->orWhere(function (Builder $q3) {
                        $q3->whereNotNull('product_type_detected_at')
                            ->where('is_product', true)
                            ->where('url', 'like', '%/ch-%');
                    });
            });
        }

        return $query->first();
    }

    /**
     * Upcoming candidates (oldest first).
     *
     * @return Collection<int, Page>
     */
    public function pending(int $limit = 5, ?string $domain = null, bool $force = false): Collection
    {
        $limit = max(1, $limit);

        $query = $this->baseQuery(afterId: 0, domain: $domain);

        if (!$force) {
            $query->where(function (Builder $q) {
                $q->whereNull('product_type_detected_at')
                    ->orWhere(function (Builder $q2) {
                        $q2->where('is_product', true)
                            ->whereNotNull('product_type_detected_at')
                            ->whereNull('product_type_id');
                    })
                    ->orWhere(function (Builder $q3) {
                        $q3->whereNotNull('product_type_detected_at')
                            ->where('is_product', true)
                            ->where('url', 'like', '%/ch-%');
                    });
            });
        }

        return $query->limit($limit)->get();
    }
}


