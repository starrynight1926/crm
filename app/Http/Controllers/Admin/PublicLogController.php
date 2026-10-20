<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * /admin/logs — hiển thị public/logs.md dạng list, phân trang + search inline.
 * Chỉ admin hệ thống.
 */
class PublicLogController extends Controller
{
    public function index(Request $req)
    {
        $file = public_path('logs.md');
        if (! file_exists($file)) {
            return view('admin.logs', ['lines' => collect(), 'total' => 0, 'q' => '']);
        }

        $q = trim((string) $req->input('q', ''));
        $tail = (int) $req->input('tail', 500);
        $tail = max(50, min(5000, $tail));

        // Đọc N dòng cuối cho hiệu suất — file có thể to.
        $all = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        // Bỏ header markdown (dòng bắt đầu bằng '#' hoặc rỗng metadata).
        $entries = array_values(array_filter($all, fn ($l) => str_starts_with($l, '- `')));
        $total = count($entries);
        $lines = array_slice($entries, -$tail);
        if ($q !== '') {
            $lines = array_values(array_filter($lines, fn ($l) => stripos($l, $q) !== false));
        }
        $lines = array_reverse($lines); // mới nhất lên trên.

        return view('admin.logs', [
            'lines' => collect($lines),
            'total' => $total,
            'q'     => $q,
            'tail'  => $tail,
        ]);
    }
}
