<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PageLink model for storing links between pages.
 *
 * @property int $id
 * @property int $source_page_id
 * @property int $target_page_id
 * @property string|null $anchor_text
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Page $sourcePage
 * @property-read Page $targetPage
 */
class PageLink extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source_page_id',
        'target_page_id',
        'anchor_text',
    ];

    /**
     * Get the source page (the page containing the link).
     *
     * @return BelongsTo<Page, $this>
     */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'source_page_id');
    }

    /**
     * Get the target page (the page being linked to).
     *
     * @return BelongsTo<Page, $this>
     */
    public function targetPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'target_page_id');
    }
}

