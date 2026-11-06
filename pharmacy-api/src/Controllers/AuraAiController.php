<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuraAiChatService;
use App\Services\UserRightsService;

final class AuraAiController extends Controller
{
    private const MODULE = 'AURA_AI';

    private function requireAuraAi(Request $request, bool $edit = false): void
    {
        $user = $request->user() ?? [];
        (new UserRightsService())->requireModule(
            (int) $request->clientId(),
            (int) ($user['id'] ?? 0),
            (string) ($user['role'] ?? ''),
            self::MODULE,
            $edit,
        );
    }

    public function status(Request $request): Response
    {
        $this->requireAuraAi($request);
        return $this->success((new AuraAiChatService())->status(), 'AuraAI status.');
    }

    public function chat(Request $request): Response
    {
        $this->requireAuraAi($request);
        $data = $this->validate($request, [
            'message' => 'required|string|max:4000',
            'history' => 'array',
        ]);
        $history = is_array($data['history'] ?? null) ? $data['history'] : [];
        $result = (new AuraAiChatService())->chat((string) $data['message'], $history);
        return $this->success($result, 'Reply.');
    }
}
