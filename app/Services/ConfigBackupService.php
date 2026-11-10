<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Xuất / nhập cấu hình hệ thống ra file JSON.
 *
 * KHÔNG bao gồm dữ liệu nghiệp vụ (leads, payments, logs...) — chỉ bảng cấu hình.
 * Mode import:
 *  - dry-run: chỉ tính diff, không ghi.
 *  - merge:   upsert theo id, giữ nguyên dữ liệu ngoài phạm vi file.
 *  - replace: xóa toàn bộ bảng cấu hình rồi nạp lại đúng như file (nguy hiểm).
 * Trước khi ghi (merge/replace) tự động sao lưu cấu hình hiện tại ra storage/app/config-backups.
 */
class ConfigBackupService
{
    /** Thứ tự ưu tiên khi ghi — cha trước con để không vướng khoá ngoại. */
    public const TABLES = [
        // Nền tảng
        'permissions',
        'roles',
        'permission_role',
        'org_units',
        'users',
        'org_unit_managers',
        'assignments',
        'assignment_scope_nodes',
        // Trường tùy biến
        'custom_fields',
        // Chia số
        'distribution_rules',
        'rule_targets',
        'lead_caps',
        'sla_policies',
        'recall_policies',
        'user_lead_settings',
        // Dịch vụ
        'services',
        'service_phases',
        'contribution_templates',
        // Nguồn & vận hành
        'source_connections',
        'facilities',
        'staff_members',
        'import_templates',
        'report_templates',
        // Thông báo
        'notification_prefs',
        // Key-value
        'app_settings',
        'system_settings',
    ];

    /** Cột không bao giờ xuất (bí mật). */
    private const REDACT_COLUMNS = [
        'users' => ['password', 'api_token', 'remember_token'],
        'source_connections' => ['credentials'],
        'personal_access_tokens' => ['token'],
    ];

    public function export(): array
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => $this->scrub($table, (array) $r))->all();
            $tables[$table] = $rows;
        }

        return [
            'meta' => [
                'app' => config('app.name'),
                'exported_at' => now()->toIso8601String(),
                'exported_by' => optional(auth()->user())->email,
                'version' => 1,
            ],
            'tables' => $tables,
        ];
    }

    public function exportToFile(?string $filename = null): string
    {
        $filename ??= 'config-' . now()->format('Ymd-His') . '.json';
        $path = 'config-backups/' . $filename;
        Storage::disk('local')->put($path, json_encode(
            $this->export(),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));

        AuditLog::record('config_export', null, ['file' => $filename]);

        return $path;
    }

    /**
     * @return array{summary: array<string, array{file:int, db:int, add:int, update:int, delete:int}>, errors: string[]}
     */
    public function diff(array $payload): array
    {
        $this->assertPayload($payload);

        $summary = [];
        foreach (self::TABLES as $table) {
            $fileRows = $payload['tables'][$table] ?? [];
            $dbRows = DB::table($table)->get()->map(fn ($r) => $this->scrub($table, (array) $r))->all();
            $dbCount = count($dbRows);

            if ($this->isPivotTable($table)) {
                // Bảng không có id đơn — so sánh bằng chữ ký toàn hàng.
                $fileSigs = array_map(fn ($r) => $this->rowSignature($r), $fileRows);
                $dbSigs = array_map(fn ($r) => $this->rowSignature($r), $dbRows);
                $add = count(array_diff($fileSigs, $dbSigs));
                $del = count(array_diff($dbSigs, $fileSigs));
                $update = 0;
            } else {
                $pk = $this->primaryKey($table);
                $fileById = [];
                foreach ($fileRows as $r) {
                    $id = $r[$pk] ?? null;
                    if ($id !== null) {
                        $fileById[$id] = $r;
                    }
                }
                $dbById = [];
                foreach ($dbRows as $r) {
                    $id = $r[$pk] ?? null;
                    if ($id !== null) {
                        $dbById[$id] = $r;
                    }
                }
                $add = count(array_diff_key($fileById, $dbById));
                $del = count(array_diff_key($dbById, $fileById));
                $update = 0;
                foreach (array_intersect_key($fileById, $dbById) as $id => $fileRow) {
                    if ($this->rowSignature($fileRow) !== $this->rowSignature($dbById[$id])) {
                        $update++;
                    }
                }
            }

            $summary[$table] = [
                'file' => count($fileRows),
                'db' => $dbCount,
                'add' => $add,
                'update' => $update,
                'delete' => $del,
            ];
        }

        return ['summary' => $summary, 'errors' => []];
    }

    private function isPivotTable(string $table): bool
    {
        return in_array($table, [
            'permission_role',
            'assignment_scope_nodes',
            'org_unit_managers',
            'user_lead_settings',
        ], true);
    }

    /**
     * @param  array  $payload  nội dung file JSON đã decode
     * @param  string  $mode  'merge' | 'replace'
     */
    public function import(array $payload, string $mode): array
    {
        $this->assertPayload($payload);
        if (! in_array($mode, ['merge', 'replace'], true)) {
            throw new RuntimeException('Chế độ nhập không hợp lệ.');
        }

        // Backup hiện trạng trước khi ghi.
        $backupPath = $this->exportToFile('auto-before-import-' . now()->format('Ymd-His') . '.json');

        $stats = [];
        DB::transaction(function () use ($payload, $mode, &$stats) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                $order = self::TABLES;
                if ($mode === 'replace') {
                    // Xoá theo thứ tự ngược để nhẹ khoá ngoại (dù đã tắt).
                    foreach (array_reverse($order) as $table) {
                        DB::table($table)->truncate();
                    }
                }

                foreach ($order as $table) {
                    $rows = $payload['tables'][$table] ?? [];
                    $inserted = 0;
                    $updated = 0;
                    $columns = DB::getSchemaBuilder()->getColumnListing($table);

                    $isPivot = $this->isPivotTable($table);
                    foreach ($rows as $row) {
                        $row = array_intersect_key($row, array_flip($columns));
                        // Trong mode merge: giữ password/api_token cũ nếu file không có.
                        $row = $this->restoreRedacted($table, $row);

                        if ($mode === 'replace') {
                            DB::table($table)->insert($row);
                            $inserted++;
                            continue;
                        }

                        if ($isPivot) {
                            // Bảng pivot không có id đơn — chỉ chèn nếu chưa có hàng khớp toàn bộ cột.
                            $q = DB::table($table);
                            foreach ($row as $col => $val) {
                                $q->where($col, $val);
                            }
                            if (! $q->exists()) {
                                DB::table($table)->insert($row);
                                $inserted++;
                            }
                            continue;
                        }

                        $pk = $this->primaryKey($table);
                        if (isset($row[$pk]) && DB::table($table)->where($pk, $row[$pk])->exists()) {
                            DB::table($table)->where($pk, $row[$pk])->update($row);
                            $updated++;
                        } else {
                            DB::table($table)->insert($row);
                            $inserted++;
                        }
                    }

                    $stats[$table] = ['inserted' => $inserted, 'updated' => $updated];
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });

        AuditLog::record('config_import', null, [
            'mode' => $mode,
            'backup_before' => $backupPath,
            'stats' => $stats,
        ]);

        return ['backup_before' => $backupPath, 'stats' => $stats];
    }

    public function listBackupFiles(): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists('config-backups')) {
            return [];
        }
        $files = $disk->files('config-backups');
        rsort($files);

        return array_map(fn ($f) => [
            'name' => basename($f),
            'path' => $f,
            'size' => $disk->size($f),
            'modified_at' => date('Y-m-d H:i:s', $disk->lastModified($f)),
        ], $files);
    }

    private function scrub(string $table, array $row): array
    {
        foreach (self::REDACT_COLUMNS[$table] ?? [] as $col) {
            if (array_key_exists($col, $row)) {
                unset($row[$col]);
            }
        }

        return $row;
    }

    /**
     * Khi merge, nếu file không có cột bí mật (password/api_token) thì lấy lại giá trị cũ trong DB
     * để không làm mất mật khẩu của user hiện có.
     */
    private function restoreRedacted(string $table, array $row): array
    {
        $redacted = self::REDACT_COLUMNS[$table] ?? [];
        if ($redacted === []) {
            return $row;
        }
        $pk = $this->primaryKey($table);
        if (! isset($row[$pk])) {
            return $row;
        }
        $existing = DB::table($table)->where($pk, $row[$pk])->first();
        if (! $existing) {
            return $row;
        }
        foreach ($redacted as $col) {
            if (! array_key_exists($col, $row) && property_exists($existing, $col)) {
                $row[$col] = $existing->$col;
            }
        }

        return $row;
    }

    private function primaryKey(string $table): string
    {
        return match ($table) {
            'permission_role' => 'role_id',
            'assignment_scope_nodes' => 'assignment_id',
            'org_unit_managers' => 'org_unit_id',
            'user_lead_settings' => 'user_id',
            default => 'id',
        };
    }

    private function rowSignature(array $row): string
    {
        ksort($row);

        return md5(json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    private function assertPayload(array $payload): void
    {
        if (! isset($payload['tables']) || ! is_array($payload['tables'])) {
            throw new RuntimeException('File cấu hình không đúng định dạng (thiếu khoá "tables").');
        }
    }
}
