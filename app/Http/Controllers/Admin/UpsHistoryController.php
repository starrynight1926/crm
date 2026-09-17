<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DailyAttendance;
use App\Models\PoolUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /admin/ups-history — xem + import/export lịch sử UPS DailyAttendance.
 * Chỉ admin hệ thống (perm user.manage).
 */
class UpsHistoryController extends Controller
{
    public function index(Request $req)
    {
        $q = DailyAttendance::query()->with(['user:id,name,email', 'facility:id,name']);

        if ($from = $req->input('from')) $q->whereDate('work_date', '>=', $from);
        if ($to   = $req->input('to'))   $q->whereDate('work_date', '<=', $to);
        if ($fid  = $req->input('facility_pool_unit_id')) $q->where('facility_pool_unit_id', $fid);
        if ($bkt  = $req->input('list_bucket')) $q->where('list_bucket', $bkt);
        if ($req->boolean('is_mkt')) $q->where('is_mkt', true);

        $rows = $q->orderByDesc('work_date')->orderByDesc('id')->paginate(50)->withQueryString();
        $facilities = PoolUnit::where('kind', 'facility')->orderBy('name')->get(['id', 'name']);

        return view('admin.ups-history', compact('rows', 'facilities'));
    }

    public function export(Request $req): StreamedResponse
    {
        $q = DailyAttendance::query()->with(['user:id,name,email', 'facility:id,name']);
        if ($from = $req->input('from')) $q->whereDate('work_date', '>=', $from);
        if ($to   = $req->input('to'))   $q->whereDate('work_date', '<=', $to);
        if ($fid  = $req->input('facility_pool_unit_id')) $q->where('facility_pool_unit_id', $fid);

        $filename = 'ups-history-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 cho Excel
            fputcsv($out, ['id', 'work_date', 'user_id', 'user_name', 'user_email',
                'facility_pool_unit_id', 'facility_name', 'list_bucket', 'is_mkt', 'is_off',
                'checkin_at', 'dung_nhan_lead', 'dung_nhan_lead_since']);
            $q->orderBy('work_date')->orderBy('id')->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $r) {
                    fputcsv($out, [
                        $r->id, optional($r->work_date)->format('Y-m-d'),
                        $r->user_id, $r->user?->name, $r->user?->email,
                        $r->facility_pool_unit_id, $r->facility?->name,
                        $r->list_bucket, $r->is_mkt ? 1 : 0, $r->is_off ? 1 : 0,
                        optional($r->checkin_at)->format('Y-m-d H:i:s'),
                        $r->dung_nhan_lead ? 1 : 0,
                        optional($r->dung_nhan_lead_since)->format('Y-m-d H:i:s'),
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $req)
    {
        $data = $req->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);
        $file = $req->file('file');
        $fh = fopen($file->getRealPath(), 'r');
        if (! $fh) return back()->with('error', 'Không đọc được file.');

        // Bỏ BOM.
        $first = fgetcsv($fh);
        if ($first && $first[0] !== null) $first[0] = ltrim($first[0], "\xEF\xBB\xBF");
        $headers = array_map('strtolower', $first ?: []);
        $required = ['work_date', 'user_id', 'facility_pool_unit_id'];
        foreach ($required as $r) {
            if (! in_array($r, $headers, true)) {
                fclose($fh);
                return back()->with('error', "Thiếu cột bắt buộc: {$r}. Cột yêu cầu: " . implode(', ', $required));
            }
        }

        $upserts = 0; $skipped = 0; $errors = [];
        $lineNo = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $lineNo++;
            if (count($row) !== count($headers)) { $skipped++; continue; }
            $r = array_combine($headers, $row);
            try {
                DailyAttendance::updateOrCreate(
                    ['user_id' => (int) $r['user_id'], 'work_date' => $r['work_date']],
                    array_filter([
                        'facility_pool_unit_id' => (int) $r['facility_pool_unit_id'],
                        'list_bucket'           => $r['list_bucket'] ?? null,
                        'is_mkt'                => isset($r['is_mkt']) ? (bool) $r['is_mkt'] : false,
                        'is_off'                => isset($r['is_off']) ? (bool) $r['is_off'] : false,
                        'checkin_at'            => ! empty($r['checkin_at']) ? $r['checkin_at'] : null,
                    ], fn ($v) => $v !== null || $v === false || $v === 0)
                );
                $upserts++;
            } catch (\Throwable $e) {
                $errors[] = "Dòng {$lineNo}: " . $e->getMessage();
                $skipped++;
            }
        }
        fclose($fh);

        return back()->with('status', "Import: upsert={$upserts}, skip={$skipped}" . ($errors ? ' — Lỗi: ' . implode(' | ', array_slice($errors, 0, 3)) : ''));
    }
}
