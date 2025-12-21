<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $trace_id
 * @property string $provider
 * @property string|null $model
 * @property string|null $http_method
 * @property string|null $base_url
 * @property string|null $path
 * @property int|null $status_code
 * @property int|null $duration_ms
 * @property array<string, mixed>|null $request_payload
 * @property array<string, mixed>|null $response_payload
 * @property string|null $response_body
 * @property array<string, mixed>|null $usage
 * @property array<string, mixed>|null $error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class AiRequestLog extends Model
{
    public const PROVIDER_OPENROUTER = 'openrouter';
    public const PROVIDER_OPENAI_COMPATIBLE = 'openai_compatible';
    public const PROVIDER_OLLAMA = 'ollama';

    protected $table = 'ai_request_logs';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'trace_id',
        'provider',
        'model',
        'http_method',
        'base_url',
        'path',
        'status_code',
        'duration_ms',
        'request_payload',
        'response_payload',
        'response_body',
        'usage',
        'error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'usage' => 'array',
        'error' => 'array',
    ];
}


