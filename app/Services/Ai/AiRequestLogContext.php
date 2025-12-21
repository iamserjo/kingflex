<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRequestLog;

final readonly class AiRequestLogContext
{
    public function __construct(
        public AiRequestLog $log,
        public float $startedAtMicrotime,
    ) {}
}


