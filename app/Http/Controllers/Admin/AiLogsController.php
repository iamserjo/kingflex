<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiRequestLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AiLogsController extends Controller
{
    public function index(Request $request): View
    {
        $provider = $request->query('provider');
        $provider = is_string($provider) && trim($provider) !== '' ? trim($provider) : null;

        $model = $request->query('model');
        $model = is_string($model) && trim($model) !== '' ? trim($model) : null;

        $status = $request->query('status');
        $status = is_string($status) && trim($status) !== '' ? trim($status) : null;

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(10, min(200, $perPage));

        $logs = AiRequestLog::query()
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->when($model !== null, fn ($q) => $q->where('model', $model))
            ->when($status !== null, function ($q) use ($status) {
                if ($status === 'ok') {
                    $q->whereNull('error');
                } elseif ($status === 'error') {
                    $q->whereNotNull('error');
                } elseif (is_numeric($status)) {
                    $q->where('status_code', (int) $status);
                }
            })
            ->orderByDesc('id')
            ->paginate($perPage);

        return view('admin.logs.ai', [
            'logs' => $logs,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'perPage' => $perPage,
        ]);
    }
}


