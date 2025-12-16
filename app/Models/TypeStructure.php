<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string $type_normalized
 * @property array<int, string> $tags
 * @property array<string, mixed> $structure
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class TypeStructure extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'type_normalized',
        'tags',
        'structure',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'structure' => 'array',
        ];
    }
}



