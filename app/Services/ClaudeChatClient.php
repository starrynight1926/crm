<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 2026-08-12 — Client gọi Anthropic Messages API cho route /ai-coop.
 * Không dùng cho luồng CRM chính — chỉ dev tool 2 AI chat chung với user.
 */
class ClaudeChatClient
{
    public const MODEL = 'claude-opus-4-7';
    public const MAX_TOKENS = 1500;

    public static function chat(string $apiKey, string $systemPrompt, array $messages): string
    {
        if (! $apiKey) {
            return '[Chưa cấu hình API key]';
        }

        try {
            $r = Http::timeout(60)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => self::MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => $systemPrompt,
                    'messages' => $messages,
                ]);

            if (! $r->successful()) {
                Log::warning('AI-Coop API fail', ['status' => $r->status(), 'body' => $r->body()]);
                return '[API HTTP ' . $r->status() . ': ' . substr($r->body(), 0, 200) . ']';
            }

            $body = $r->json();
            $text = '';
            foreach (($body['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                }
            }
            return trim($text) ?: '[Empty response]';
        } catch (\Throwable $e) {
            Log::warning('AI-Coop API exception: ' . $e->getMessage());
            return '[Lỗi mạng: ' . $e->getMessage() . ']';
        }
    }
}
