<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact model for storing contact data extracted from pages.
 *
 * @property int $id
 * @property int $page_id
 * @property string|null $company_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property array|null $social_links
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Page $page
 */
class Contact extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'page_id',
        'company_name',
        'email',
        'phone',
        'address',
        'social_links',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'social_links' => 'array',
        ];
    }

    /**
     * Get the page this contact was extracted from.
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Check if this contact has any social links.
     */
    public function hasSocialLinks(): bool
    {
        return !empty($this->social_links);
    }

    /**
     * Get a specific social link by platform name.
     */
    public function getSocialLink(string $platform): ?string
    {
        return $this->social_links[$platform] ?? null;
    }
}

