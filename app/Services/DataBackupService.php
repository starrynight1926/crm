<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Xuất toàn bộ dữ liệu hệ thống ra file zip gồm:
 *   - data_khach.xlsx    (dữ liệu khách hàng, chăm sóc, thu tiền)
 *   - data_congty.xlsx   (dịch vụ, cơ sở, kết nối nguồn, log hệ thống, thống kê)
 *   - data_nhansu.xlsx   (người dùng, tổ chức, phân quyền, danh mục nhân sự)
 *   - config.json        (file cấu hình có thể nhập lại — xem ConfigBackupService)
 *
 * File Excel chỉ để lưu trữ / xem lại, KHÔNG dùng để nhập lại vào hệ thống.
 * Nếu cần nhập lại cấu hình, dùng chức năng "Nhập cấu hình" với file config.json.
 */
class DataBackupService
{
    private const REDACT_COLUMNS = [
        'users' => ['password', 'api_token', 'remember_token'],
        'source_connections' => ['credentials'],
        'personal_access_tokens' => ['token'],
    ];

    /** Nhóm sheet cho từng file Excel. Nhãn tiếng Việt để hiển thị làm tên sheet. */
    private const SHEETS_KHACH = [
        'Khách hàng' => ['conn' => 'mysql', 'table' => 'leads'],
        'Giá trị trường tùy biến' => ['conn' => 'mysql', 'table' => 'lead_custom_values'],
        'Lịch sử chăm sóc' => ['conn' => 'mysql', 'table' => 'lead_status_logs'],
        'Điều trị' => ['conn' => 'mysql', 'table' => 'lead_treatments'],
        'Nâng cấp dịch vụ' => ['conn' => 'mysql', 'table' => 'lead_upsells'],
        'Dịch vụ khách sử dụng' => ['conn' => 'mysql', 'table' => 'customer_services'],
        'Giai đoạn dịch vụ khách' => ['conn' => 'mysql', 'table' => 'customer_service_phases'],
        'Thanh toán' => ['conn' => 'mysql', 'table' => 'payments'],
        'Đóng góp doanh thu' => ['conn' => 'mysql', 'table' => 'contributions'],
        'Nhật ký chia số' => ['conn' => 'mysql', 'table' => 'lead_distribution_logs'],
        'Lead thô (chưa xử lý)' => ['conn' => 'pgsql', 'table' => 'raw_leads'],
    ];

    private const SHEETS_CONGTY = [
        'Danh mục dịch vụ' => ['conn' => 'mysql', 'table' => 'services'],
        'Giai đoạn dịch vụ' => ['conn' => 'mysql', 'table' => 'service_phases'],
        'Mẫu đóng góp' => ['conn' => 'mysql', 'table' => 'contribution_templates'],
        'Cơ sở' => ['conn' => 'mysql', 'table' => 'facilities'],
        'Kết nối nguồn' => ['conn' => 'mysql', 'table' => 'source_connections'],
        'Lô import' => ['conn' => 'pgsql', 'table' => 'import_batches'],
        'Nhật ký ingest' => ['conn' => 'pgsql', 'table' => 'ingest_logs'],
        'Thống kê hàng ngày' => ['conn' => 'mysql', 'table' => 'stats_daily'],
        'Nhật ký hệ thống' => ['conn' => 'mysql', 'table' => 'audit_logs'],
        'Thiết lập ứng dụng' => ['conn' => 'mysql', 'table' => 'app_settings'],
        'Thiết lập hệ thống' => ['conn' => 'mysql', 'table' => 'system_settings'],
    ];

    private const SHEETS_NHANSU = [
        'Người dùng' => ['conn' => 'mysql', 'table' => 'users'],
        'Đơn vị tổ chức' => ['conn' => 'mysql', 'table' => 'org_units'],
        'Người quản lý đơn vị' => ['conn' => 'mysql', 'table' => 'org_unit_managers'],
        'Vai trò' => ['conn' => 'mysql', 'table' => 'roles'],
        'Quyền hạn' => ['conn' => 'mysql', 'table' => 'permissions'],
        'Vai trò – Quyền' => ['conn' => 'mysql', 'table' => 'permission_role'],
        'Phân công' => ['conn' => 'mysql', 'table' => 'assignments'],
        'Phạm vi phân công' => ['conn' => 'mysql', 'table' => 'assignment_scope_nodes'],
        'Nhân sự chuyên môn' => ['conn' => 'mysql', 'table' => 'staff_members'],
        'Trường tùy biến' => ['conn' => 'mysql', 'table' => 'custom_fields'],
    ];

    public function __construct(private ConfigBackupService $configService) {}

    /**
     * Tạo file zip đầy đủ, trả về đường dẫn tương đối trên disk local.
     */
    public function build(?int $requestedBy = null): string
    {
        $stamp = now()->format('Ymd-His');
        $zipRelative = 'backups/lara-scrm-backup-' . $stamp . '.zip';
        $zipAbsolute = Storage::disk('local')->path($zipRelative);

        // Bảo đảm thư mục tồn tại.
        Storage::disk('local')->makeDirectory('backups');

        // Ghi 3 file Excel + config.json vào thư mục tạm rồi nén lại.
        $tmpDir = Storage::disk('local')->path('backups/tmp-' . $stamp);
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }

        try {
            $this->writeExcel($tmpDir . DIRECTORY_SEPARATOR . 'data_khach.xlsx', self::SHEETS_KHACH);
            $this->writeExcel($tmpDir . DIRECTORY_SEPARATOR . 'data_congty.xlsx', self::SHEETS_CONGTY);
            $this->writeExcel($tmpDir . DIRECTORY_SEPARATOR . 'data_nhansu.xlsx', self::SHEETS_NHANSU);
            file_put_contents(
                $tmpDir . DIRECTORY_SEPARATOR . 'config.json',
                json_encode($this->configService->export(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            );
            file_put_contents(
                $tmpDir . DIRECTORY_SEPARATOR . 'README.txt',
                $this->readmeContent($stamp),
            );

            $zip = new ZipArchive();
            if ($zip->open($zipAbsolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Không thể tạo file zip: ' . $zipAbsolute);
            }
            foreach (['data_khach.xlsx', 'data_congty.xlsx', 'data_nhansu.xlsx', 'config.json', 'README.txt'] as $name) {
                $zip->addFile($tmpDir . DIRECTORY_SEPARATOR . $name, $name);
            }
            $zip->close();
        } finally {
            // Dọn thư mục tạm.
            foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }

        AuditLog::record('full_backup', null, [
            'file' => basename($zipRelative),
            'size' => filesize($zipAbsolute),
            'requested_by' => $requestedBy,
        ]);

        return $zipRelative;
    }

    public function listBackupFiles(): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists('backups')) {
            return [];
        }
        $files = array_filter($disk->files('backups'), fn ($f) => str_ends_with($f, '.zip'));
        rsort($files);

        return array_map(fn ($f) => [
            'name' => basename($f),
            'path' => $f,
            'size' => $disk->size($f),
            'modified_at' => date('Y-m-d H:i:s', $disk->lastModified($f)),
        ], $files);
    }

    /** @param  array<string, array{conn:string, table:string}>  $sheets */
    private function writeExcel(string $absolutePath, array $sheets): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $index = 0;
        foreach ($sheets as $title => $spec) {
            $sheet = $spreadsheet->createSheet($index++);
            $sheet->setTitle(mb_substr($title, 0, 31));

            $columns = DB::connection($spec['conn'])->getSchemaBuilder()->getColumnListing($spec['table']);
            if ($columns === []) {
                $sheet->setCellValue('A1', 'Không có dữ liệu.');
                continue;
            }

            $redact = self::REDACT_COLUMNS[$spec['table']] ?? [];
            $exportCols = array_values(array_diff($columns, $redact));

            $sheet->fromArray($exportCols, null, 'A1');
            $sheet->getStyle('A1:' . $this->col(count($exportCols)) . '1')->getFont()->setBold(true);
            $sheet->getStyle('A1:' . $this->col(count($exportCols)) . '1')->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER);

            $row = 2;
            DB::connection($spec['conn'])->table($spec['table'])->orderBy($this->orderKey($spec['table'], $columns))
                ->chunk(1000, function ($chunk) use ($sheet, $exportCols, &$row) {
                    foreach ($chunk as $record) {
                        $values = [];
                        foreach ($exportCols as $col) {
                            $v = ((array) $record)[$col] ?? null;
                            $values[] = is_array($v) || is_object($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                        }
                        $sheet->fromArray($values, null, 'A' . $row++);
                    }
                });

            foreach (range(1, count($exportCols)) as $i) {
                $sheet->getColumnDimension($this->col($i))->setAutoSize(false);
                $sheet->getColumnDimension($this->col($i))->setWidth(22);
            }
            $sheet->freezePane('A2');
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($absolutePath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    private function col(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    private function orderKey(string $table, array $columns): string
    {
        return in_array('id', $columns, true) ? 'id' : $columns[0];
    }

    private function readmeContent(string $stamp): string
    {
        return "Sao lưu hệ thống Lara-SCRM\n"
            . "Thời điểm tạo: " . now()->format('d/m/Y H:i:s') . " ({$stamp})\n"
            . "\n"
            . "Nội dung gói:\n"
            . " - data_khach.xlsx   : dữ liệu khách hàng và quá trình chăm sóc.\n"
            . " - data_congty.xlsx  : dịch vụ, cơ sở, kết nối nguồn, log hệ thống, thống kê.\n"
            . " - data_nhansu.xlsx  : người dùng, tổ chức, phân quyền, danh mục nhân sự.\n"
            . " - config.json       : cấu hình hệ thống ở dạng máy đọc được — có thể nhập lại qua chức năng \"Nhập cấu hình\".\n"
            . "\n"
            . "Lưu ý: các file Excel dùng để lưu trữ và tra cứu. Việc khôi phục cấu hình phải dùng file config.json thông qua giao diện quản trị.\n";
    }
}
