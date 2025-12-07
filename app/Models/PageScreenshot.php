<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * PageScreenshot model for storing page screenshots.
 *
 * @property int $id
 * @property int $page_id
 * @property string $path
 * @property string $format
 * @property int|null $width
 * @property int|null $height
 * @property int|null $file_size
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Page $page
 */
class PageScreenshot extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'page_id',
        'path',
        'format',
        'width',
        'height',
        'file_size',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'file_size' => 'integer',
        ];
    }

    /**
     * Get the page this screenshot belongs to.
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the full path to the screenshot file.
     */
    public function getFullPath(): string
    {
        return Storage::disk('local')->path($this->path);
    }

    /**
     * Get the screenshot as base64 encoded string.
     */
    public function getBase64(): ?string
    {
        $fullPath = $this->getFullPath();

        if (!file_exists($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        $mimeType = $this->format === 'png' ? 'image/png' : 'image/jpeg';

        return "data:{$mimeType};base64," . base64_encode($content);
    }

    /**
     * Delete the screenshot file when the model is deleted.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (PageScreenshot $screenshot) {
            Storage::disk('local')->delete($screenshot->path);
        });
    }
}

