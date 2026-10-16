<?php

namespace App\Support;

/**
 * Ghi hoạt động vào file public/logs.md — Apache/Nginx serve trực tiếp,
 * ứng dụng sập vẫn đọc được. Gated bằng .htaccess check cookie `scrm_authed`.
 * Append-only, 1 dòng markdown per event.
 */
class PublicLog
{
    public static function write(string $action, ?string $detail = null): void
    {
        $file = public_path('logs.md');
        $u = auth()->user();
        $who = $u
            ? "user#{$u->id} ({$u->name})"
            : 'guest';
        $ip = request()?->ip() ?? '-';
        $ts = now()->format('Y-m-d H:i:s');
        $line = "- `{$ts}` **{$who}** — {$action}" . ($detail ? " · {$detail}" : "") . " · _IP {$ip}_" . PHP_EOL;

        // Header nếu file chưa tồn tại.
        if (!file_exists($file)) {
            @file_put_contents($file, "# Nhật ký hoạt động\n\nAppend-only. Rotate tay khi to quá.\n\n");
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
