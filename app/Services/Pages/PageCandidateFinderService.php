<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Models\Page;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared utility to find the "next" Page candidate for stage-like commands/jobs.
 *
 * This avoids duplicating the same pagination pattern across commands:
 * - afterId cursor
 * - optional domain filter
 * - stable ordering
 *
 * Usage:
 *   $page = $finder->nextCandidate(
 *     afterId: $lastId,
 *     domain: $domain,
 *     configure: static function (Builder $q): void {
 *       $q->whereNotNull('content_with_tags_purified')->whereNull('embedding');
 *     },
 *   );
 */
final class PageCandidateFinderService
{
    /**
     * @param Closure(Builder<Page>):void $configure
     */
    public function nextCandidate(int $afterId, ?string $domain, Closure $configure): ?Page
    {
        /** @var Builder<Page> $query */
        $query = Page::query()->where('id', '>', $afterId);

        if ($domain !== null && $domain !== '') {
            $query->whereHas('domain', static fn (Builder $q) => $q->where('domain', $domain));
        }

        $configure($query);

        // Ensure deterministic ordering unless overridden.
        // If caller added their own orderBy, Eloquent will keep both; that's fine.
        $query->orderBy('id');

        return $query->first();
    }
}


