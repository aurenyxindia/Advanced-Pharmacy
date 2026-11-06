<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Support\Env;

/**
 * AuraAI pharmacy assistant chat (Retail aura-ai-chat parity) — server-side only; no keys in browser.
 */
final class AuraAiChatService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are AuraAI, a helpful retail pharmacy assistant. Your role is to provide accurate, concise information for pharmacy staff and customers. Focus on:

1. **Drug uses** – What the medicine is used for (indications).
2. **Side effects** – Common and important adverse effects; when to seek medical help.
3. **Dosage** – Typical adult/child doses, frequency, route (oral/IV etc.); mention "as per prescription" when dose varies.
4. **Brand substitution** – Alternative brands or generic equivalents when asked; mention composition (e.g. same salt) and that substitution should follow local regulations and prescription.

Keep answers clear and professional. If you are unsure or the question is outside pharmacy/drug info, say so. Do not diagnose or replace a doctor's advice. For dosage, prefer "consult prescription / physician" when specific dosing is critical.
PROMPT;

    public function isConfigured(): bool
    {
        return (new AiBillExtractService())->isConfigured();
    }

    /** @return array{configured:bool, provider:string, message:?string} */
    public function status(): array
    {
        return (new AiBillExtractService())->status();
    }

    /**
     * @param list<array{role:string, content:string}> $history
     * @return array{text:string}
     */
    public function chat(string $userMessage, array $history = []): array
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            throw HttpException::validation(['message' => ['Message is required.']]);
        }
        if (mb_strlen($userMessage) > 4000) {
            throw HttpException::validation(['message' => ['Message is too long.']]);
        }

        $ai = new AiBillExtractService();
        if (!$ai->isConfigured()) {
            throw new HttpException(
                'AuraAI is not configured. Set OPENAI_API_KEY or GEMINI_API_KEY in server .env.',
                501
            );
        }

        $provider = $ai->provider();
        $text = match ($provider) {
            'openai' => $this->chatOpenAi($userMessage, $history),
            'gemini' => $this->chatGemini($userMessage, $history),
            default => throw new HttpException('No AI provider configured.', 501),
        };

        return ['text' => $text !== '' ? $text : 'No response.'];
    }

    /** @param list<array{role:string, content:string}> $history */
    private function chatOpenAi(string $userMessage, array $history): string
    {
        $apiKey = $this->openaiKey();
        if ($apiKey === '') {
            throw new HttpException('OpenAI API key missing.', 501);
        }
        $model = (string) Env::get('OPENAI_MODEL', Env::get('AI_MODEL', 'gpt-4o'));
        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        foreach ($this->normalizeHistory($history) as $row) {
            $messages[] = $row;
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $body = [
            'model' => $model,
            'max_tokens' => 2048,
            'temperature' => 0.4,
            'messages' => $messages,
        ];

        $response = $this->httpJson(
            'https://api.openai.com/v1/chat/completions',
            $body,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]
        );

        return trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    }

    /** @param list<array{role:string, content:string}> $history */
    private function chatGemini(string $userMessage, array $history): string
    {
        $apiKey = $this->geminiKey();
        if ($apiKey === '') {
            throw new HttpException('Gemini API key missing.', 501);
        }
        $model = (string) Env::get('GEMINI_MODEL', Env::get('AI_MODEL', 'gemini-2.0-flash'));
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        $parts = [['text' => self::SYSTEM_PROMPT . "\n\n"]];
        foreach ($this->normalizeHistory($history) as $row) {
            $label = $row['role'] === 'assistant' ? 'Assistant' : 'User';
            $parts[] = ['text' => $label . ': ' . $row['content'] . "\n"];
        }
        $parts[] = ['text' => 'User: ' . $userMessage . "\n\nAssistant:"];

        $body = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 2048,
            ],
        ];

        $response = $this->httpJson($url, $body, ['Content-Type: application/json']);
        return trim((string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    }

    /**
     * @param list<array{role:string, content:string}> $history
     * @return list<array{role:string, content:string}>
     */
    private function normalizeHistory(array $history): array
    {
        $out = [];
        foreach (array_slice($history, -20) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = strtolower(trim((string) ($row['role'] ?? '')));
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }
        return $out;
    }

    /** @param array<string, mixed> $body */
    private function httpJson(string $url, array $body, array $headers): array
    {
        $payload = json_encode($body);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new HttpException('AI provider error: ' . ($err ?: 'network failure'), 502);
        }
        $decoded = json_decode($response, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'AI request failed')
                : 'AI request failed';
            throw new HttpException($msg, $code >= 400 && $code < 600 ? $code : 502);
        }
        if (!is_array($decoded)) {
            throw new HttpException('AI provider returned non-JSON.', 502);
        }
        return $decoded;
    }

    private function openaiKey(): string
    {
        foreach (['OPENAI_API_KEY', 'AI_API_KEY'] as $k) {
            $v = trim((string) Env::get($k, ''));
            if ($v !== '' && !str_contains($v, 'YOUR_') && (str_starts_with($v, 'sk-') || $k === 'OPENAI_API_KEY')) {
                if ($k === 'AI_API_KEY' && !str_starts_with($v, 'sk-')) {
                    continue;
                }
                return $v;
            }
        }
        return '';
    }

    private function geminiKey(): string
    {
        foreach (['GEMINI_API_KEY', 'AI_API_KEY'] as $k) {
            $v = trim((string) Env::get($k, ''));
            if ($v !== '' && !str_contains($v, 'YOUR_') && !str_starts_with($v, 'sk-')) {
                return $v;
            }
        }
        return '';
    }
}
