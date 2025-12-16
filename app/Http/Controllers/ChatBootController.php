<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ChatBoot\ChatBootService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ChatBootController extends Controller
{
    public function __construct(
        private readonly ChatBootService $chatBoot,
    ) {}

    public function index(): View
    {
        return view('chatboot');
    }

    public function message(Request $request): JsonResponse
    {
        $reset = $request->boolean('reset');

        $validated = $request->validate([
            'message' => $reset
                ? ['nullable', 'string', 'max:4000']
                : ['required', 'string', 'min:1', 'max:4000'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        $result = $this->chatBoot->sendMessage(
            message: (string) ($validated['message'] ?? ''),
            reset: $reset,
        );

        return response()->json($result);
    }
}



