<?php

use App\Jobs\ProcessRawLead;
use App\Models\CustomField;
use App\Models\ImportBatch;
use App\Models\ImportTemplate;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\RawLead;
use App\Models\User;
use App\Support\SpreadsheetReader;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public $file = null;

    public array $headers = [];

    /** @var array<int, array> Toàn bộ dòng trong file (không giới hạn 5). */
    public array $preview = [];

    /**
     * Lỗi validate theo dòng.
     * Format: [rowIndex => ['name' => 'Thiếu tên', 'phone' => 'SĐT không hợp lệ: abc123', '_row' => 'Trùng dòng #3']].
     * `_row` = lỗi mức dòng (không gắn 1 cột cụ thể).
     */
    public array $rowErrors = [];

    public int $errorRowCount = 0;

    public int $validRowCount = 0;

    public bool $validated = false;

    public ?string $storedPath = null;

    public string $storedExtension = '';

    public string $storedName = '';

    /** @var array<string, string> target => cột file (index dạng string, '' = bỏ qua) */
    public array $mapping = [];

    /** @var array<string, string> target => giá trị mặc định nếu ô trống */
    public array $defaults = [];

    public string $selectedTemplateId = '';

    public string $templateName = '';

    /** Mẫu import theo phòng: '' = công ty, 'org:{id}' = phòng cụ thể. */
    public string $selectedOrgTemplate = '';

    public ?int $lastBatchId = null;

    public function mount(): void
    {
        // Default: chọn sẵn cơ sở của user để nút "Tải file mẫu" ra đúng bộ trường theo phòng.
        $orgs = $this->visibleOrgOptions();
        if ($orgs->isNotEmpty()) {
            $this->selectedOrgTemplate = 'org:' . $orgs->first()->id;
        }
    }

    /**
     * Danh sách phòng/team được phép chọn để tải mẫu.
     * User chỉ thấy org trong phạm vi của mình (tránh HN tải nhầm mẫu HCM). Hợp union:
     * - visibleOrgUnitIds() — dùng cho user có scope xem (manager/admin thấy nhiều nhánh).
     * - Descendants của cơ sở gần nhất từ assignment anchor — dùng cho data-entry (SELF scope,
     *   visibleOrgUnitIds rỗng nhưng vẫn cần nhập cho các team trong cùng cơ sở).
     */
    private function visibleOrgOptions(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        if (! $user) return collect();

        $ids = $user->visibleOrgUnitIds();

        $anchorIds = $user->assignments()
            ->where('active', true)
            ->pluck('org_unit_id')
            ->unique()
            ->all();
        $basePaths = collect();
        if ($anchorIds) {
            $anchors = OrgUnit::whereIn('id', $anchorIds)->get(['id', 'depth', 'path']);
            $basePaths = $anchors->map(function (OrgUnit $o) {
                if ($o->depth <= 1) {
                    return $o->path;
                }
                $parts = array_values(array_filter(explode('/', $o->path)));
                return '/' . $parts[0] . '/' . $parts[1] . '/';
            })->unique()->values();
        }

        if (! $ids && $basePaths->isEmpty()) {
            return collect();
        }

        return OrgUnit::query()
            ->where(function ($q) use ($ids, $basePaths) {
                if ($ids) {
                    $q->orWhereIn('id', $ids);
                }
                foreach ($basePaths as $p) {
                    $q->orWhere('path', 'like', $p . '%');
                }
            })
            ->orderBy('path')
            ->get();
    }

    /**
     * Giới hạn dòng mỗi lần import. Pipeline hiện dispatch từng job đồng bộ trong request Livewire —
     * file > 5000 dòng dễ timeout + tốn RAM đọc all-at-once. Muốn nạp lớn hơn cần refactor sang streaming
     * + batch job (chưa làm, xem result.md ngày 2026-07-17).
     */
    public const MAX_ROWS_PER_IMPORT = 5000;

    // Field lead chuẩn (scope.md mục 4)
    public const TARGETS = [
        'name'             => 'Tên khách hàng',
        'phone'            => 'SĐT',
        'received_date'    => 'Ngày nhập',
        'source_group'     => 'Nhóm nguồn',
        // 2026-08-05: cột "Phương thức chia" cho trực page. Giá trị = "Tự động" hoặc tên kho ở sheet 2.
        'distribution'     => 'Phương thức chia',
        'insight'          => 'Ghi chú insight khách',
        'link'             => 'Link',
        'birthday'         => 'Ngày sinh',
        'occupation'       => 'Nghề nghiệp',
        'address'          => 'Địa chỉ',
        'medical_history'  => 'Khai thác tiền sử',
        'booking_owner'    => 'Email Booking phụ trách',
        'sale_owner'       => 'Email Sale phụ trách',
    ];

    private const GUESS = [
        'name'             => ['tên', 'ten', 'name', 'họ tên', 'khách hàng'],
        'phone'            => ['sđt', 'sdt', 'phone', 'điện thoại', 'so dien thoai'],
        'received_date'    => ['ngày nhập', 'ngày', 'ngay', 'date'],
        'source_group'     => ['nhóm nguồn', 'nguồn', 'nguon', 'source', 'source_group'],
        'distribution'     => ['phương thức chia', 'phuong thuc chia', 'chia', 'distribution'],
        'insight'          => ['insight', 'ghi chú insight', 'ghi chu insight'],
        'link'             => ['link', 'url'],
        'birthday'         => ['ngày sinh', 'ngay sinh', 'birthday', 'dob'],
        'occupation'       => ['nghề nghiệp', 'nghe nghiep', 'occupation'],
        'address'          => ['địa chỉ', 'dia chi', 'address'],
        'medical_history'  => ['tiền sử', 'tien su', 'medical', 'khai thác'],
        'booking_owner'    => ['người booking', 'nguoi booking', 'booking phụ trách', 'booking_owner', 'tele phụ trách'],
        'sale_owner'       => ['người sale', 'nguoi sale', 'sale phụ trách', 'sale_owner', 'chia cho', 'owner'],
    ];

    /** Trường tùy biến đang áp (active) → ['cf_<id>' => 'Nhãn #MÃ (Phòng)']. */
    private function customTargets(): array
    {
        $out = [];
        foreach ($this->customFields() as $f) {
            $scope = $f->org_unit_id === null ? 'Công ty' : ($f->orgUnit?->name ?? 'Phòng');
            $code = $f->import_code ? " #{$f->import_code}" : '';
            $req = $f->required ? ' *' : '';
            $out['cf_' . $f->id] = $f->label . $code . ' (' . $scope . ')' . $req;
        }
        return $out;
    }

    /**
     * Custom field áp cho map cột: lọc theo phòng/team đã chọn ở bước 1 (field công ty +
     * field của org đó và các cha), tránh liệt kê field của team khác. Chưa chọn org →
     * chỉ field mức công ty.
     */
    private function customFields()
    {
        $orgUnit = null;
        if (str_starts_with($this->selectedOrgTemplate, 'org:')) {
            $orgUnit = \App\Models\OrgUnit::find((int) substr($this->selectedOrgTemplate, 4));
        }
        return CustomField::applicableTo($orgUnit)->load('orgUnit');
    }

    /** Toàn bộ target: field chuẩn + trường tùy biến. */
    private function allTargets(): array
    {
        return array_merge(self::TARGETS, $this->customTargets());
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    /** Đổi phòng/team sau khi đã upload → bộ target thay đổi → phải re-init mapping. */
    public function updatedSelectedOrgTemplate(): void
    {
        if (! $this->preview) return;
        $this->initMappingDefaults();
        if ($this->selectedTemplateId !== '') {
            $this->applyTemplate();
        } else {
            $this->autoGuess();
        }
        $this->rowErrors = [];
        $this->errorRowCount = 0;
        $this->validRowCount = 0;
        $this->validated = false;
    }

    public function updatedFile(): void
    {
        $this->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:20480']);

        $this->storedExtension = $this->file->getClientOriginalExtension();
        $this->storedName = $this->file->getClientOriginalName();
        $this->storedPath = $this->file->store('imports');

        $data = SpreadsheetReader::read(storage_path('app/private/' . $this->storedPath), $this->storedExtension);
        $this->headers = $data['headers'];
        $this->preview = $data['rows']; // Load TOÀN BỘ dòng để validate + hiển thị.
        $this->rowErrors = [];
        $this->errorRowCount = 0;
        $this->validRowCount = 0;
        $this->validated = false;

        $this->initMappingDefaults();

        if ($this->selectedTemplateId !== '') {
            $this->applyTemplate();
        } else {
            $this->autoGuess();
        }
    }

    private function initMappingDefaults(): void
    {
        $keys = array_keys($this->allTargets());
        $this->mapping = array_fill_keys($keys, '');
        $this->defaults = array_fill_keys($keys, '');
    }

    /** Tự đoán mapping theo tên cột: field chuẩn theo từ khóa, custom theo import_code hoặc nhãn. */
    private function autoGuess(): void
    {
        $fields = $this->customFields();
        $custom = $this->customTargets();

        foreach ($this->headers as $index => $header) {
            $h = $this->norm((string) $header);
            if ($h === '') {
                continue;
            }
            foreach (self::GUESS as $target => $keywords) {
                if ($this->mapping[$target] === '' && array_filter($keywords, fn ($k) => str_contains($h, $k))) {
                    $this->mapping[$target] = (string) $index;
                    break;
                }
            }
            // Custom fields: ưu tiên match theo import_code, fallback theo nhãn
            foreach ($fields as $f) {
                $target = 'cf_' . $f->id;
                if (($this->mapping[$target] ?? '') !== '') {
                    continue;
                }
                if ($f->import_code && $h === $this->norm($f->import_code)) {
                    $this->mapping[$target] = (string) $index;
                    continue;
                }
                $label = $custom[$target] ?? '';
                $base = $this->norm(preg_replace('/\s*[#(].*$/', '', $label));
                if ($base !== '' && $h === $base) {
                    $this->mapping[$target] = (string) $index;
                }
            }
        }
    }

    public function applyTemplate(): void
    {
        if ($this->selectedTemplateId === '' || ! $this->headers) {
            return;
        }
        $tpl = ImportTemplate::find($this->selectedTemplateId);
        if (! $tpl) {
            return;
        }

        $this->initMappingDefaults();
        // index theo header đã chuẩn hóa
        $byHeader = [];
        foreach ($this->headers as $i => $h) {
            $byHeader[$this->norm((string) $h)] = (string) $i;
        }
        foreach ($tpl->config ?? [] as $entry) {
            $target = $entry['target'] ?? null;
            if (! $target || ! array_key_exists($target, $this->mapping)) {
                continue;
            }
            $header = $this->norm((string) ($entry['header'] ?? ''));
            if ($header !== '' && isset($byHeader[$header])) {
                $this->mapping[$target] = $byHeader[$header];
            }
            $this->defaults[$target] = (string) ($entry['default'] ?? '');
        }
        session()->flash('status', "Đã áp template \"{$tpl->name}\".");
    }

    public function saveTemplate(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);
        $this->validate(['templateName' => 'required|string|max:100'], [], ['templateName' => 'tên template']);

        $labels = $this->allTargets();
        $config = [];
        foreach ($this->mapping as $target => $colIndex) {
            $default = trim((string) ($this->defaults[$target] ?? ''));
            if ($colIndex === '' && $default === '') {
                continue; // target không map + không mặc định → bỏ
            }
            $config[] = [
                'target' => $target,
                'header' => $colIndex !== '' ? (string) ($this->headers[(int) $colIndex] ?? '') : '',
                'default' => $default,
            ];
        }

        ImportTemplate::create([
            'name' => $this->templateName,
            'config' => $config,
            'created_by' => auth()->id(),
        ]);
        $this->templateName = '';
        session()->flash('status', 'Đã lưu template.');
    }

    public function deleteTemplate(int $id): void
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);
        ImportTemplate::whereKey($id)->delete();
        if ($this->selectedTemplateId === (string) $id) {
            $this->selectedTemplateId = '';
        }
    }

    public function downloadSample(int $id)
    {
        $tpl = ImportTemplate::findOrFail($id);
        $headers = collect($tpl->config ?? [])
            ->pluck('header')
            ->filter(fn ($h) => trim((string) $h) !== '')
            ->values()
            ->all();

        if (empty($headers)) {
            session()->flash('status', 'Template chưa có cột nào để tạo file mẫu.');
            return;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
        }

        $this->appendSalesReferenceSheet($spreadsheet, null);

        $filename = 'mau-import-' . \Illuminate\Support\Str::slug($tpl->name) . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        }, $filename);
    }

    /** Tải file mẫu theo phòng ban đang chọn (trường chuẩn + trường tùy biến áp dụng). */
    public function downloadBlankSample()
    {
        $orgUnit = null;
        $slug = 'cong-ty';
        if (str_starts_with($this->selectedOrgTemplate, 'org:')) {
            $orgUnit = \App\Models\OrgUnit::find((int) substr($this->selectedOrgTemplate, 4));
            $slug = $orgUnit ? \Illuminate\Support\Str::slug($orgUnit->name) : 'phong';
        }

        $fields = CustomField::applicableTo($orgUnit);

        // Phase C1.f 2026-08-02: template rút gọn chỉ 4 cột core + trường bổ sung phòng.
        //   Trực Page / Admin cơ sở nhập ban đầu — các field khác (note, chia cho, phân loại, …)
        //   nhập tay sau ở app. Cột khác vẫn map tay được ở bước 2 nếu file user có sẵn.
        // 2026-08-02: header sạch (không có * / hướng dẫn) để import auto-map exact. Legend nằm ở sheet "Hướng dẫn".
        $coreHeaders = self::TARGETS;
        $headers = array_values($coreHeaders);
        foreach ($fields as $f) {
            if ($f->field_type === 'code' && ($f->rules['code_kind'] ?? '') === 'fixed') {
                continue;
            }
            $code = $f->import_code ?: $f->label;
            $req = $f->required ? ' *' : '';
            $headers[] = $code . $req;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
        }
        // Sample row (dòng 2) demo giá trị đúng format.
        $sheet->setCellValue('A2', 'Nguyễn Văn A (demo — xoá dòng này)');
        $sheet->setCellValue('B2', '0999000001');
        $sheet->setCellValue('C2', now()->format('Y-m-d'));
        $sheet->setCellValue('D2', 'MKT');
        $sheet->setCellValue('E2', 'Tự động'); // cột "Phương thức chia"
        $sheet->getStyle('A2:E2')->getFont()->getColor()->setARGB('FF888888');
        $sheet->freezePane('A2');
        $sheet->setTitle('Import');

        $this->appendGuideSheet($spreadsheet);
        $this->appendSourceLegendSheet($spreadsheet);
        // 2026-08-05: sheet "Danh mục kho" + data validation cho cột "Phương thức chia".
        $poolLastRow = $this->appendPoolListSheet($spreadsheet);
        $this->appendBookingListSheet($spreadsheet);
        $this->appendSaleListSheet($spreadsheet);

        // Áp data validation dropdown cho cột "Phương thức chia" (nếu có trong headers).
        $distIdx = array_search('distribution', array_keys($coreHeaders), true);
        if ($distIdx !== false) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($distIdx + 1);
            for ($r = 2; $r <= 200; $r++) {
                $v = $sheet->getCell($col . $r)->getDataValidation();
                $v->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
                $v->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
                $v->setAllowBlank(true);
                $v->setShowDropDown(true);
                $v->setShowErrorMessage(true);
                $v->setErrorTitle('Không hợp lệ');
                $v->setError('Chọn từ danh mục ở sheet "Danh mục kho".');
                $v->setFormula1("'Danh mục kho'!\$A\$2:\$A\$" . $poolLastRow);
            }
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        }, "mau-import-{$slug}.xlsx");
    }

    /**
     * 2026-08-05 — Danh sách "Phương thức chia" cho import (dùng ở sheet 2 + parser + data validation).
     * Trả về array [tên nhập → target] để match nhanh khi parse.
     *   'Tự động' → 'auto'
     *   'Kho công ty' → 'company'
     *   'Kho Chi nhánh HN' → 'pool:<id>'  (PoolUnit kind=branch)
     *   'Kho địa điểm 59NTN' → 'pool:<id>' (kind=facility)
     *   'Kho cơ sở PKD 1 (Team Giang)' → 'pool:<id>' (kind=department)
     */
    public static function distributionOptions(): array
    {
        $opts = ['Tự động' => 'auto'];
        $company = \App\Models\PoolUnit::where('kind', 'company')->first();
        if ($company) $opts['Kho công ty'] = 'pool:' . $company->id;

        // Sắp theo cây liền mạch: branch → facility con → department con.
        $branches = \App\Models\PoolUnit::where('kind', 'branch')->where('is_active', true)
            ->orderBy('sort')->orderBy('name')->get();
        foreach ($branches as $branch) {
            $opts['Kho Chi nhánh ' . self::shortName($branch->name)] = 'pool:' . $branch->id;

            $facilities = \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
                ->where('parent_id', $branch->id)->orderBy('sort')->orderBy('name')->get();
            foreach ($facilities as $fac) {
                $opts['Kho địa điểm ' . self::shortName($fac->name)] = 'pool:' . $fac->id;

                $depts = \App\Models\PoolUnit::where('kind', 'department')->where('is_active', true)
                    ->where('parent_id', $fac->id)->orderBy('sort')->orderBy('name')->get();
                foreach ($depts as $dept) {
                    $opts['Kho cơ sở ' . self::shortName($dept->name)] = 'pool:' . $dept->id;
                }
            }
        }
        return $opts;
    }

    /** Rút tên PoolUnit gọn hơn cho hiển thị. VD "CS1: 59 Ngô Thì Nhậm" → "59NTN"? Không, giữ nguyên. */
    private static function shortName(string $name): string
    {
        // Bỏ "CS1: " / "CS: " prefix nếu có.
        return preg_replace('/^CS\d*:\s*/u', '', $name);
    }

    /** Sheet "Danh mục kho" — cây liền mạch dùng cho data validation cột "Phương thức chia". */
    private function appendPoolListSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): int
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Danh mục kho');
        $sheet->setCellValue('A1', 'Tên (copy sang cột "Phương thức chia")');
        $sheet->setCellValue('B1', 'Mô tả');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getStyle('A1:B1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEE9D6');

        $row = 2;
        $opts = self::distributionOptions();
        foreach ($opts as $label => $target) {
            $desc = match (true) {
                $target === 'auto' => 'Chia ngay từ UPS list ngày hôm nay (round-robin)',
                str_starts_with($target, 'pool:') => (function () use ($target) {
                    $p = \App\Models\PoolUnit::find((int) substr($target, 5));
                    return $p ? ($p->kind . ' · ' . $p->name) : '?';
                })(),
                default => '',
            };
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $desc);
            // Indent bằng padding style theo cấp (giả indent bằng font size).
            $row++;
        }
        $sheet->getColumnDimension('A')->setWidth(50);
        $sheet->getColumnDimension('B')->setWidth(60);
        return $row - 1; // Số dòng data cuối (dùng cho data validation range).
    }

    /** Sheet "7 nguồn" — bảng tham chiếu mã nguồn ↔ mô tả ↔ ai up. */
    private function appendSourceLegendSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('7 nguồn');
        $rows = [
            ['Mã nguồn', 'Tên đầy đủ', 'Ai up (theo flow)'],
            ['MKT',    'Marketing (fanpage / QC)',              'Trực Page'],
            ['MKT_BR', 'Marketing Branch (chi nhánh chạy QC)',  'Sale nhân viên'],
            ['BDM',    'Business Development Manager',           'QL Sale / Admin cơ sở'],
            ['BOD',    'Ban lãnh đạo giới thiệu',                'QL Sale / Admin cơ sở'],
            ['SA',     'Sale Appointment (Sale mang về)',        'QL Sale / Admin cơ sở'],
            ['BA',     'Booking Appointment (Booker mang về)',   'Tele (Booker)'],
            ['WI',     'Walk-in (khách tự đến)',                 'QL Sale / Admin cơ sở'],
        ];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $val) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
                $sheet->setCellValue($col . ($r + 1), $val);
            }
        }
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEE9D6');
        foreach (['A', 'B', 'C'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    /** Sheet "Hướng dẫn" — giải thích từng cột (giữ tên cột trong sheet Import sạch để import auto-map). */
    private function appendGuideSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Hướng dẫn');

        $rows = [
            ['Cột', 'Bắt buộc', 'Áp cho nguồn nào', 'Format / Ghi chú'],
            ['Tên khách hàng',          'CÓ',    'Mọi nguồn',                'Họ tên đầy đủ. VD: Nguyễn Văn A'],
            ['SĐT',                     'CÓ',    'Mọi nguồn',                'Bắt đầu 0 hoặc +84. Dán từ Excel bị mất số 0 đầu (VD 912345678) hệ thống tự thêm lại → thành 0912345678.'],
            ['Ngày nhập',               'không', 'Mọi nguồn',                'DD-MM-YYYY hoặc YYYY-MM-DD. Bỏ trống = ngày hôm nay.'],
            ['Nhóm nguồn',              'CÓ',    'Mọi nguồn',                'Mã nguồn — xem sheet "7 nguồn". Chỉ được up nguồn nằm trong quyền của mày (Trực Page = MKT; QL Sale/Admin cơ sở = BDM/BOD/WI; Sale = MKT_BR/SA; Tele = BA). Up sai nguồn → dòng bị fail.'],
            ['Phương thức chia',        'không', 'CHỈ MKT',                  'Chỉ có tác dụng khi Nhóm nguồn = MKT. Giá trị: "Tự động" (chia từ UPS list) hoặc tên kho ở sheet "Danh mục kho". Nguồn khác điền vô sẽ bị BỎ QUA — lead luôn vào kho chung chờ CM chia.'],
            ['Ghi chú insight khách',   'không', 'Mọi nguồn',                'Text tự do — insight ban đầu về khách.'],
            ['Link',                    'không', 'Mọi nguồn',                'URL nguồn (fanpage, comment, form, …).'],
            ['Ngày sinh',               'không', 'Mọi nguồn',                'DD-MM-YYYY. VD: 25-12-1990.'],
            ['Nghề nghiệp',             'không', 'Mọi nguồn',                'Text tự do.'],
            ['Địa chỉ',                 'không', 'Mọi nguồn',                'Text tự do.'],
            ['Khai thác tiền sử',       'không', 'Mọi nguồn',                'Text nhiều dòng — bệnh lý, dịch vụ đã dùng, …'],
            ['Email Booking phụ trách', 'không', '(chưa dùng — dự phòng)',    'Cột dự phòng, hiện KHÔNG có tác dụng ở pipeline. Nguồn MKT/MKT_BR/BDM lead luôn vào kho Booking chờ CM booking chia.'],
            ['Email Sale phụ trách',    'không', 'CHỈ BOD / SA / BA / WI',   'Email chính xác của Sale — xem sheet "List Sale". Có email hợp lệ → gán thẳng lead cho sale đó (bỏ qua kho chờ). Nguồn MKT/MKT_BR/BDM điền vô sẽ bị BỎ QUA (luồng booking, chưa tới sale).'],
            ['(Trường bổ sung)',        'tùy',   'Theo phòng đã chọn',       'Các cột phía sau là trường tùy biến của phòng đã chọn ở bước tải mẫu (có * = bắt buộc theo config phòng).'],
        ];

        // 2026-08-10: liệt kê giá trị hợp lệ cho từng field select (Phân loại, Kết quả, S.I.C...)
        // để user copy-paste. Lấy fresh từ CustomField applicable.
        $orgUnit = null;
        if (str_starts_with($this->selectedOrgTemplate, 'org:')) {
            $orgUnit = \App\Models\OrgUnit::find((int) substr($this->selectedOrgTemplate, 4));
        }
        foreach (CustomField::applicableTo($orgUnit) as $f) {
            if ($f->field_type !== 'select' || ! is_array($f->options) || empty($f->options)) {
                continue;
            }
            $opts = implode(' · ', array_map(fn ($o) => is_array($o) ? ($o['label'] ?? $o['value'] ?? '') : (string) $o, $f->options));
            $rows[] = [
                $f->label,
                $f->required ? 'CÓ' : 'không',
                'Trường bổ sung',
                'Chọn 1 trong các giá trị: ' . $opts,
            ];
        }
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $val) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1);
                $sheet->setCellValue($col . ($r + 1), $val);
            }
        }
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEE9D6');
        $sheet->getStyle('A2:A' . (count($rows)))->getFont()->setBold(true);
        foreach (['A', 'B', 'C', 'D'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('D')->setWidth(80);
        // Wrap text cho cột D (mô tả dài).
        $sheet->getStyle('D2:D' . count($rows))->getAlignment()->setWrapText(true);
    }

    /** Sheet "List Booking" — Tele (Team Nhập Lead + Team Tele) chia theo cơ sở. */
    private function appendBookingListSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): void
    {
        $this->appendStaffPerFacilitySheet($spreadsheet, 'List Booking', ['lead.import', 'source.up.tele']);
    }

    /** Sheet "List Sale" — user có perm consult (Sale / CM sale) chia theo cơ sở. */
    private function appendSaleListSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): void
    {
        $this->appendStaffPerFacilitySheet($spreadsheet, 'List Sale', ['lead.consult']);
    }

    /**
     * Helper: list user active có ÍT NHẤT 1 trong các perm được nêu, group theo cơ sở gốc.
     * Layout: mỗi cơ sở 1 nhóm — hàng header cơ sở đậm nền vàng nhạt, các user liệt kê bên dưới (tên · email).
     */
    private function appendStaffPerFacilitySheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $title, array $anyPerms): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        // Root orgs = chi nhánh cơ sở (depth=1) hoặc theo Facility::roots.
        $roots = \App\Models\Facility::roots()->orderBy('name')->get();
        if ($roots->isEmpty()) {
            // Fallback theo OrgUnit depth 1.
            $roots = OrgUnit::where('depth', 1)->orderBy('name')->get();
        }

        $sheet->setCellValue('A1', 'Cơ sở');
        $sheet->setCellValue('B1', 'Tên nhân viên');
        $sheet->setCellValue('C1', 'Email / Username');
        $sheet->setCellValue('D1', 'Vai trò');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEE9D6');

        $row = 2;
        foreach ($roots as $root) {
            // Resolve org subtree cho branch này.
            $branchOrg = OrgUnit::where('name', 'like', '%' . $root->name . '%')->orWhere('code', 'like', '%' . \Illuminate\Support\Str::slug($root->name) . '%')->first();
            $orgIds = $branchOrg
                ? OrgUnit::where('path', 'like', $branchOrg->path . '%')->pluck('id')
                : collect();

            $users = User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->when($orgIds->isNotEmpty(), fn ($q) => $q->whereHas('assignments', fn ($a) => $a->where('active', true)->whereIn('org_unit_id', $orgIds)))
                ->with(['assignments' => fn ($q) => $q->where('active', true)->with(['role', 'orgUnit'])])
                ->orderBy('name')
                ->get()
                ->filter(function ($u) use ($anyPerms) {
                    foreach ($anyPerms as $p) if ($u->hasPermission($p)) return true;
                    return false;
                });

            if ($users->isEmpty()) continue;

            // Header cơ sở
            $sheet->setCellValue('A' . $row, '📍 ' . $root->name);
            $sheet->mergeCells('A' . $row . ':D' . $row);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $sheet->getStyle('A' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF5EAD0');
            $row++;

            foreach ($users as $u) {
                $roleName = $u->assignments->first()?->role?->name ?? '';
                $sheet->setCellValue('B' . $row, $u->name);
                $sheet->setCellValue('C' . $row, $u->email ?: $u->username);
                $sheet->setCellValue('D' . $row, $roleName);
                $row++;
            }
            $row++; // spacer
        }

        foreach (['A', 'B', 'C', 'D'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    /**
     * Thêm sheet "DS Sale" liệt kê user active + phòng ban → người điền cột CHIA CHO biết
     * tên chính xác, biết tên nào bị trùng để chuyển sang điền email.
     * $scope null → list tất cả sale active (mẫu công ty).
     * $scope là OrgUnit → chỉ list user assigned vào org đó hoặc descendant (VD team Hợi
     * kèm sub-team Booking/Sale).
     */
    private function appendSalesReferenceSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ?OrgUnit $scope): void
    {
        $query = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->with(['assignments' => fn ($q) => $q->where('active', true)->with('orgUnit')])
            ->orderBy('name');

        if ($scope) {
            $scopeOrgIds = OrgUnit::where('path', 'like', $scope->path . '%')->pluck('id');
            $query->whereHas('assignments', function ($q) use ($scopeOrgIds) {
                $q->where('active', true)->whereIn('org_unit_id', $scopeOrgIds);
            });
        }

        $users = $query->get(['id', 'name', 'email']);

        $rows = $users->map(function (User $u) {
            $orgs = $u->assignments
                ->map(fn ($a) => $a->orgUnit?->name)
                ->filter()->unique()->values()->implode(', ');
            return [
                'name' => $u->name,
                'org' => $orgs,
                'email' => $u->email,
            ];
        })->values();

        $duplicateNames = $rows
            ->groupBy(fn ($r) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $r['name']))))
            ->filter(fn ($g) => $g->count() > 1)
            ->keys()
            ->all();

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('DS Sale');

        $sheet->setCellValue('A1', 'Tên sale (điền vào cột CHIA CHO)');
        $sheet->setCellValue('B1', 'Phòng / Team');
        $sheet->setCellValue('C1', 'Email');
        $sheet->setCellValue('D1', 'Ghi chú');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit("A{$r}", $row['name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$r}", $row['org'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$r}", $row['email'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $norm = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $row['name'])));
            if (in_array($norm, $duplicateNames, true)) {
                $sheet->setCellValue("D{$r}", 'TRÙNG TÊN — nên điền email (cột C) thay vì tên để chắc chắn khớp đúng người');
                $sheet->getStyle("A{$r}:D{$r}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FFF3CD');
            }
            $r++;
        }

        $note = $spreadsheet->createSheet();
        $note->setTitle('Hướng dẫn');
        $note->setCellValue('A1', 'Cột CHIA CHO (gán cho sale)');
        $note->getStyle('A1')->getFont()->setBold(true);
        $note->setCellValue('A2', 'Điền TÊN hoặc EMAIL của sale. Copy đúng từ sheet "DS Sale".');
        $note->setCellValue('A3', 'Ưu tiên điền EMAIL — chắc chắn không nhầm, đặc biệt khi có nhiều sale trùng tên.');
        $note->setCellValue('A4', 'Nếu điền tên: hệ thống khớp theo trùng đủ họ tên → trùng phần đuôi tên → chứa chuỗi.');
        $note->setCellValue('A5', 'Nếu có nhiều người cùng khớp tên → dòng đó bỏ qua (lead vào kho chung). Muốn tránh, điền email.');
        $note->setCellValue('A6', 'Bỏ trống cột này → lead vào kho chung, engine chia số tự chia.');
        $note->getColumnDimension('A')->setWidth(90);

        $spreadsheet->setActiveSheetIndex(0);
    }

    /**
     * Chỉ validate — KHÔNG ghi DB. Sau khi bấm gọi này, `rowErrors` + `errorRowCount` được điền để UI bôi đỏ.
     * All-or-nothing: chỉ khi validate 100% sạch mới cho phép bấm "Import" (button riêng).
     */
    public function validateFile(): void
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);

        $this->resetErrorBag();
        $this->rowErrors = [];
        $this->errorRowCount = 0;
        $this->validRowCount = 0;
        $this->validated = false;

        if (! $this->storedPath || ! $this->preview) {
            $this->addError('file', 'Chưa chọn file.');
            return;
        }
        if (($this->mapping['name'] ?? '') === '' || ($this->mapping['phone'] ?? '') === '') {
            $this->addError('mapping', 'Bắt buộc map cột Tên và SĐT trước khi kiểm tra.');
            return;
        }

        // Trường tùy biến bắt buộc — nếu chưa map và không có default, coi như file thiếu.
        foreach ($this->customFields() as $f) {
            if (! $f->required) continue;
            $target = 'cf_' . $f->id;
            $mapped = ($this->mapping[$target] ?? '') !== '';
            $hasDefault = trim((string) ($this->defaults[$target] ?? '')) !== '';
            if (! $mapped && ! $hasDefault) {
                $code = $f->import_code ? " (#{$f->import_code})" : '';
                $this->addError('mapping', 'Trường bắt buộc chưa map hoặc chưa có mặc định: ' . $f->label . $code);
                return;
            }
        }

        $rowCount = count($this->preview);
        if ($rowCount > self::MAX_ROWS_PER_IMPORT) {
            $this->addError('file', sprintf(
                'File có %s dòng, vượt giới hạn %s dòng/lần import. Vui lòng chia nhỏ file rồi upload lại.',
                number_format($rowCount),
                number_format(self::MAX_ROWS_PER_IMPORT),
            ));
            return;
        }

        $nameCol = (int) $this->mapping['name'];
        $phoneCol = (int) $this->mapping['phone'];

        // Chuẩn hoá + dò trùng theo SĐT.
        $normalizedByRow = [];
        $existingPhones = []; // phone => leadId đã có trong DB
        foreach ($this->preview as $i => $row) {
            $nameV = trim((string) ($row[$nameCol] ?? ''));
            $phoneV = trim((string) ($row[$phoneCol] ?? ''));

            if ($nameV === '' && $phoneV === '') {
                // Bỏ qua dòng rác — không tính vào tổng cần import.
                continue;
            }

            if ($nameV === '') {
                $this->rowErrors[$i]['name'] = 'Thiếu tên khách hàng.';
            }
            if ($phoneV === '') {
                $this->rowErrors[$i]['phone'] = 'Thiếu số điện thoại.';
                continue;
            }

            $norm = Lead::normalizePhone($phoneV);
            if ($norm === null) {
                $this->rowErrors[$i]['phone'] = 'SĐT không hợp lệ.';
                continue;
            }
            $normalizedByRow[$i] = $norm;
        }

        // Trùng SĐT với lead có sẵn trong DB.
        $normList = array_values(array_unique($normalizedByRow));
        if ($normList) {
            $existingPhones = Lead::whereIn('phone', $normList)->pluck('id', 'phone')->all();
        }
        // Trùng nội bộ trong cùng file: dòng nào xuất hiện sau lần đầu → đánh dấu.
        $seenPhone = [];
        foreach ($normalizedByRow as $rowIdx => $phone) {
            if (isset($existingPhones[$phone])) {
                $this->rowErrors[$rowIdx]['phone'] = 'Trùng SĐT với lead #' . $existingPhones[$phone] . ' đã có.';
                continue;
            }
            if (isset($seenPhone[$phone])) {
                $this->rowErrors[$rowIdx]['phone'] = 'Trùng SĐT với dòng #' . ($seenPhone[$phone] + 1) . ' trong file.';
                continue;
            }
            $seenPhone[$phone] = $rowIdx;
        }

        // Tính tổng thực (bỏ dòng rác).
        $totalReal = 0;
        foreach ($this->preview as $i => $row) {
            $nameV = trim((string) ($row[$nameCol] ?? ''));
            $phoneV = trim((string) ($row[$phoneCol] ?? ''));
            if ($nameV === '' && $phoneV === '') continue;
            $totalReal++;
        }

        $this->errorRowCount = count($this->rowErrors);
        $this->validRowCount = $totalReal - $this->errorRowCount;
        $this->validated = true;
    }

    public function import()
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);

        // Bắt buộc validate trước, và không được có lỗi.
        $this->validateFile();
        if (! $this->validated || $this->errorRowCount > 0) {
            return;
        }

        $nameCol = (int) $this->mapping['name'];
        $phoneCol = (int) $this->mapping['phone'];

        $batch = ImportBatch::create([
            'file_name' => $this->storedName,
            'uploaded_by' => auth()->id(),
            'column_mapping' => $this->mapping,
            'total' => 0,
            'created_at' => now(),
        ]);

        $count = 0;
        foreach ($this->preview as $row) {
            $nameV = trim((string) ($row[$nameCol] ?? ''));
            $phoneV = trim((string) ($row[$phoneCol] ?? ''));
            if ($nameV === '' && $phoneV === '') continue;

            $payload = [];
            foreach ($this->mapping as $target => $columnIndex) {
                $val = $columnIndex !== '' ? trim((string) ($row[(int) $columnIndex] ?? '')) : '';
                if ($val === '' && ($this->defaults[$target] ?? '') !== '') {
                    $val = trim((string) $this->defaults[$target]);
                }
                if ($val !== '') {
                    $payload[$target] = $val;
                }
            }

            // 2026-08-05: parse cột "Phương thức chia" → thêm vào payload (auto | pool:<id>).
            //   ProcessRawLead sẽ đọc field này để chia (UPS auto hoặc thả kho).
            //   Match theo tên đúng từ sheet 2. Không match → giữ raw + báo trong import log (không block).
            if (! empty($payload['distribution'])) {
                $opts = self::distributionOptions();
                $target = $opts[trim($payload['distribution'])] ?? null;
                $payload['_distribution_target'] = $target ?: null;
                if (! $target) {
                    $payload['_distribution_error'] = 'Không nhận diện được "'.$payload['distribution'].'" — kiểm tra sheet "Danh mục kho".';
                }
            }

            $raw = RawLead::create([
                'source_type' => RawLead::SOURCE_EXCEL,
                'source_ref' => $this->storedName,
                'import_batch_id' => $batch->id,
                'payload' => $payload,
                'status' => RawLead::STATUS_PENDING,
                'created_at' => now(),
            ]);
            ProcessRawLead::dispatch($raw->id);
            $count++;
        }

        $batch->update(['total' => $count]);
        session()->flash('status', "Đã nạp {$count} khách hàng vào pipeline (batch #{$batch->id}). Xem tiến độ ở mục Lịch sử import bên dưới.");
        return $this->redirect(route('leads.import'), navigate: true);
    }

    /**
     * Xuất Excel chứa các dòng lỗi + cột Lý do (mỗi lỗi 1 dòng gộp theo dòng gốc).
     */
    public function downloadErrorRows()
    {
        abort_unless(auth()->user()->hasPermission('lead.import'), 403);
        if ($this->errorRowCount === 0 || ! $this->preview) {
            session()->flash('status', 'Không có dòng lỗi để tải.');
            return;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dòng lỗi');

        // Header = header file gốc + cột "Lý do lỗi".
        $headers = $this->headers;
        $headers[] = 'Lý do lỗi';
        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $h);
        }
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1')
            ->getFont()->setBold(true);

        $r = 2;
        foreach ($this->rowErrors as $rowIdx => $errs) {
            $original = $this->preview[$rowIdx] ?? [];
            $reasons = [];
            foreach ($errs as $field => $msg) {
                $label = match ($field) {
                    'name' => 'Tên', 'phone' => 'SĐT', default => $field,
                };
                $reasons[] = "[$label] $msg";
            }
            $rowOut = array_values($original);
            $rowOut[count($headers) - 1] = implode(' | ', $reasons);
            foreach ($rowOut as $ci => $val) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
                $sheet->setCellValue($col . $r, is_scalar($val) ? $val : json_encode($val, JSON_UNESCAPED_UNICODE));
            }
            $r++;
        }

        $filename = 'loi-import-' . now()->format('Ymd-His') . '.xlsx';
        return response()->streamDownload(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        }, $filename);
    }

    public function with(): array
    {
        $batches = ImportBatch::orderByDesc('id')->limit(10)->get();
        $batches->each->refreshStats();

        return [
            'batches' => $batches,
            'targets' => $this->allTargets(),
            'templates' => ImportTemplate::orderByDesc('id')->get(),
            'orgOptions' => $this->visibleOrgOptions(),
        ];
    }
};
?>

<div wire:poll.5s>
    <div class="mb-6">
        <h1 class="text-3xl font-bold mb-1">Import dữ liệu khách hàng</h1>
        <p class="text-sm text-ink/60">Upload Excel/CSV → chọn/tạo template → map cột (kèm giá trị mặc định) → pipeline chuẩn hóa, chống trùng, đưa lead sạch vào kho chung.</p>
    </div>

    {{-- Hướng dẫn các bước --}}
    <div class="mb-6 bg-white border border-gold-200 rounded-xl shadow-card px-6 py-5">
        <h2 class="text-sm font-bold text-ink/60 uppercase tracking-wider mb-4">Quy trình import</h2>
        <div class="flex items-center justify-between gap-2 overflow-x-auto">
            @foreach ([
                ['icon' => '1', 'label' => 'Chọn mẫu import', 'desc' => 'Chọn phòng/team để tải file mẫu đúng bộ trường'],
                ['icon' => '2', 'label' => 'Điền thông tin', 'desc' => 'Nhập dữ liệu khách hàng vào file mẫu đã tải'],
                ['icon' => '3', 'label' => 'Upload lên hệ thống', 'desc' => 'Chọn file → map cột → bấm Kiểm tra dữ liệu'],
                ['icon' => '4', 'label' => 'Sửa lỗi nếu có', 'desc' => 'Ô sai bôi đỏ; tải file lỗi, sửa trên máy, upload lại'],
                ['icon' => '5', 'label' => 'Import', 'desc' => 'File 100% sạch mới cho Import; pipeline chia số tự xử lý'],
            ] as $i => $step)
                @if ($i > 0)
                    <svg class="w-5 h-5 shrink-0 text-gold-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                @endif
                <div class="flex items-start gap-3 min-w-[150px] flex-1">
                    <span class="shrink-0 w-8 h-8 rounded-full bg-gold-600 text-white font-bold text-sm flex items-center justify-center">{{ $step['icon'] }}</span>
                    <div>
                        <p class="text-sm font-semibold text-ink/80 leading-tight">{{ $step['label'] }}</p>
                        <p class="text-[11px] text-ink/50 mt-0.5 leading-snug">{{ $step['desc'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @if (session('status'))
        <p class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md px-4 py-2">{{ session('status') }}</p>
    @endif

    {{-- Bước 1: Chọn mẫu import --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6 mb-6">
        <h2 class="font-bold text-gold-700 mb-4 flex items-center gap-2">
            <span class="shrink-0 w-7 h-7 rounded-full bg-gold-600 text-white font-bold text-xs flex items-center justify-center">1</span>
            Chọn mẫu import
        </h2>
        <p class="text-xs text-ink/50 mb-4">Mẫu được tạo tự động từ trường chuẩn + trường tùy biến theo phòng ban. Chọn phòng rồi tải file mẫu.</p>
        <div class="flex items-end gap-3 flex-wrap">
            <div class="min-w-[240px]">
                <label class="block text-xs font-semibold text-ink/60 mb-1">Phòng / Team</label>
                <select wire:model.live="selectedOrgTemplate" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white">
                    <option value="">— Chỉ trường chung công ty (KHÔNG có trường riêng của team nào) —</option>
                    @foreach ($orgOptions as $o)
                        <option value="org:{{ $o->id }}">{{ str_repeat('— ', $o->depth) }}{{ $o->name }}</option>
                    @endforeach
                </select>
            </div>
            <button wire:click="downloadBlankSample"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-white bg-gold-600 hover:bg-gold-700 px-5 py-2.5 rounded-md">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                </svg>
                Tải file mẫu
            </button>
        </div>
        @if ($templates->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-gold-100 flex items-end gap-3 flex-wrap">
                <div class="min-w-[240px]">
                    <label class="block text-xs font-semibold text-ink/60 mb-1">Hoặc dùng mẫu đã lưu trước đó</label>
                    <select wire:model.live="selectedTemplateId" class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm bg-white">
                        <option value="">— chọn mẫu —</option>
                        @foreach ($templates as $tpl)
                            <option value="{{ $tpl->id }}">{{ $tpl->name }} ({{ count($tpl->config ?? []) }} cột)</option>
                        @endforeach
                    </select>
                </div>
                @if ($selectedTemplateId)
                    <button wire:click="downloadSample({{ (int) $selectedTemplateId }})"
                            class="inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-4 py-2.5 rounded-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                        </svg>
                        Tải mẫu này
                    </button>
                @endif
            </div>
        @endif

        @if ($selectedTemplateId && ($selectedTpl = $templates->firstWhere('id', (int) $selectedTemplateId)))
            <div class="mt-4 border border-gold-100 rounded-lg p-4 bg-gold-50/30">
                <div class="text-sm font-bold text-ink/70 mb-2">Trường của mẫu "{{ $selectedTpl->name }}"</div>
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-left text-ink/50 border-b border-gold-200">
                            <th class="py-1.5 pr-4 font-semibold">Cột file</th>
                            <th class="py-1.5 pr-4 font-semibold">Trường hệ thống</th>
                            <th class="py-1.5 font-semibold">Mặc định</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($selectedTpl->config ?? [] as $entry)
                            <tr class="border-b border-gold-100">
                                <td class="py-1.5 pr-4 font-medium">{{ $entry['header'] ?: '—' }}</td>
                                <td class="py-1.5 pr-4">
                                    <span class="px-1.5 py-0.5 rounded bg-gold-50 text-gold-700 border border-gold-200">{{ $targets[$entry['target']] ?? $entry['target'] }}</span>
                                </td>
                                <td class="py-1.5 text-ink/50">{{ $entry['default'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start mb-6">
        {{-- Upload + mapping --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
            <h2 class="font-bold text-gold-700 mb-4">2. Chọn file (CSV / XLSX)</h2>
            <p class="text-xs text-ink/60 mb-2">
                Giới hạn <strong>{{ number_format(self::MAX_ROWS_PER_IMPORT) }} dòng / lần import</strong>.
                File lớn hơn hãy chia nhỏ rồi import từng phần — pipeline hiện chưa được tối ưu cho batch cực lớn.
            </p>
            <input type="file" wire:model="file" accept=".csv,.xlsx,.xls"
                   class="block w-full text-sm border border-gold-200 rounded-md file:mr-3 file:px-4 file:py-2.5 file:border-0 file:bg-gold-50 file:text-gold-700 file:font-semibold file:text-sm cursor-pointer">
            @error('file')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            <div wire:loading wire:target="file" class="text-sm text-gold-600 mt-2">Đang đọc file...</div>

            @if ($headers)
                {{-- Lưu template mới --}}
                <div class="mt-5 border border-gold-100 rounded-lg p-3 bg-gold-50/40">
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label class="block text-xs font-semibold text-ink/60 mb-1">Lưu mapping hiện tại thành mẫu mới</label>
                            <input type="text" wire:model="templateName" placeholder="VD: Mẫu FB Lead Form" class="w-full border border-gold-200 rounded-md px-2.5 py-1.5 text-sm">
                            @error('templateName')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <button wire:click="saveTemplate" class="text-xs font-semibold text-gold-700 border border-gold-300 hover:bg-gold-100 px-3 py-2 rounded-md">Lưu mẫu</button>
                    </div>
                </div>

                <h2 class="font-bold text-gold-700 mt-6 mb-1">3. Map cột file → trường</h2>
                <p class="text-xs text-ink/50 mb-3">Tự đoán theo tên cột; đặt "Mặc định" cho ô trống. Trường tùy biến theo phòng nằm cuối danh sách.</p>
                @error('mapping')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror

                <div class="grid grid-cols-[1fr_1fr_0.9fr] gap-2 mb-1 px-0.5">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Trường</span>
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Cột file</span>
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-ink/40">Mặc định</span>
                </div>
                <div class="space-y-2">
                    @foreach ($targets as $target => $label)
                        <div class="grid grid-cols-[1fr_1fr_0.9fr] gap-2 items-center">
                            <label class="text-sm font-medium {{ str_starts_with($target, 'cf_') ? 'text-ink/70' : '' }}">{{ $label }}</label>
                            <select wire:model="mapping.{{ $target }}" class="border border-gold-200 rounded-md px-2 py-1.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— bỏ qua —</option>
                                @foreach ($headers as $index => $header)
                                    <option value="{{ $index }}">{{ $header ?: "Cột " . ($index + 1) }}</option>
                                @endforeach
                            </select>
                            <input type="text" wire:model="defaults.{{ $target }}" placeholder="—" class="border border-gold-200 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                    @endforeach
                </div>

                <div class="mt-6 grid grid-cols-2 gap-2">
                    <button wire:click="validateFile" wire:loading.attr="disabled"
                            class="border border-gold-400 text-gold-700 hover:bg-gold-50 font-semibold py-3 rounded-md">
                        Kiểm tra dữ liệu
                    </button>
                    <button wire:click="import" wire:loading.attr="disabled"
                            @if($validated && $errorRowCount > 0) disabled @endif
                            class="bg-gold-600 hover:bg-gold-700 disabled:bg-ink/20 disabled:cursor-not-allowed text-white font-semibold py-3 rounded-md">
                        Import
                    </button>
                </div>
                <p class="text-[11px] text-ink/50 mt-2">
                    Kiểm tra trước để bôi đỏ các dòng sai. Chỉ khi file 100% hợp lệ, nút <strong>Import</strong> mới có tác dụng.
                </p>
            @endif
        </div>

        {{-- Preview + validate --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6 overflow-x-auto">
            @php $nameColIdx = ($mapping['name'] ?? '') !== '' ? (int) $mapping['name'] : null; @endphp
            @php $phoneColIdx = ($mapping['phone'] ?? '') !== '' ? (int) $mapping['phone'] : null; @endphp

            <div class="flex items-center justify-between mb-4">
                <h2 class="font-bold text-gold-700">
                    Xem trước ({{ count($preview) }} dòng)
                    @if ($validated)
                        <span class="ml-2 text-xs font-normal">
                            <span class="text-emerald-700">Hợp lệ: {{ $validRowCount }}</span> ·
                            <span class="text-red-600">Lỗi: {{ $errorRowCount }}</span>
                        </span>
                    @endif
                </h2>
            </div>

            @if ($validated && $errorRowCount > 0)
                <div class="mb-4 flex items-center gap-3 bg-red-50 border border-red-200 text-red-700 rounded-md px-4 py-3">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                    <div class="flex-1">
                        <p class="text-sm font-semibold">Dữ liệu sai format, kiểm tra lại</p>
                        <p class="text-xs">Có {{ $errorRowCount }} / {{ $errorRowCount + $validRowCount }} dòng lỗi. Sửa file gốc rồi upload lại — hệ thống không cho phép nhập file có lỗi.</p>
                    </div>
                    <button wire:click="downloadErrorRows"
                            class="shrink-0 text-xs font-semibold bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-md">
                        Tải file lỗi
                    </button>
                </div>
            @elseif ($validated && $errorRowCount === 0)
                <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-md px-4 py-3 text-sm">
                    ✓ Tất cả {{ $validRowCount }} dòng đều hợp lệ. Bấm <strong>Import</strong> để đưa vào hệ thống.
                </div>
            @endif

            @if ($preview)
                <p class="mb-3 text-xs text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
                    <strong>Chú ý:</strong> những cột không được khớp (map) ở bước trên sẽ không được nhập vào hệ thống. Trường hợp có dòng sai định dạng, toàn bộ quá trình nhập sẽ bị hủy — phải sửa file rồi upload lại.
                </p>
                <div class="max-h-[420px] overflow-auto border border-gold-100 rounded">
                    <table class="w-full text-xs whitespace-nowrap">
                        <thead class="sticky top-0 z-10">
                            <tr class="text-left bg-gold-50 text-ink/60 uppercase tracking-wider">
                                <th class="px-2 py-2 font-semibold w-10 text-center">#</th>
                                @foreach ($headers as $index => $header)
                                    <th class="px-2.5 py-2 font-semibold">
                                        {{ $header }}
                                        @if ($index === $nameColIdx)<span class="ml-1 text-[9px] text-gold-700">TÊN</span>@endif
                                        @if ($index === $phoneColIdx)<span class="ml-1 text-[9px] text-gold-700">SĐT</span>@endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gold-100">
                            @foreach ($preview as $rowIdx => $row)
                                @php $errs = $rowErrors[$rowIdx] ?? []; @endphp
                                <tr class="{{ $errs ? 'bg-red-50/40' : '' }}">
                                    <td class="px-2 py-1.5 text-center text-ink/40 tabular-nums">{{ $rowIdx + 1 }}</td>
                                    @foreach ($headers as $index => $_)
                                        @php
                                            $errMsg = null;
                                            if ($index === $nameColIdx && isset($errs['name'])) $errMsg = $errs['name'];
                                            elseif ($index === $phoneColIdx && isset($errs['phone'])) $errMsg = $errs['phone'];
                                        @endphp
                                        <td class="px-2.5 py-1.5 {{ $errMsg ? 'bg-red-200/70 text-red-900 font-medium' : '' }}"
                                            @if ($errMsg) title="{{ $errMsg }}" @endif>
                                            {{ $row[$index] ?? '' }}
                                            @if ($errMsg)
                                                <span class="block text-[10px] text-red-700 font-normal">⚠ {{ $errMsg }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-ink/40">Chọn file để xem trước nội dung.</p>
            @endif
        </div>
    </div>

    {{-- Thống kê batch --}}
    <div class="bg-white border border-gold-200 rounded-xl shadow-card">
        <div class="px-6 py-4 border-b border-gold-100 flex items-center justify-between">
            <h2 class="text-lg font-bold">Lịch sử import</h2>
            <span class="text-xs text-ink/40">Tự cập nhật mỗi 5 giây</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-ink/50 bg-gold-50/60">
                    <th class="px-5 py-3 font-semibold">#</th>
                    <th class="px-5 py-3 font-semibold">File</th>
                    <th class="px-5 py-3 font-semibold">Thời gian</th>
                    <th class="px-5 py-3 font-semibold text-right">Tổng</th>
                    <th class="px-5 py-3 font-semibold text-right text-green-700">Thành công</th>
                    <th class="px-5 py-3 font-semibold text-right text-amber-600">Trùng (đã gộp)</th>
                    <th class="px-5 py-3 font-semibold text-right text-red-600">Lỗi</th>
                    <th class="px-5 py-3 font-semibold text-right">Đang chờ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gold-100">
                @forelse ($batches as $batch)
                    @php $pending = $batch->total - $batch->success - $batch->failed - $batch->duplicated; @endphp
                    <tr class="{{ $batch->id === $lastBatchId ? 'bg-gold-50/60' : '' }}">
                        <td class="px-5 py-3 text-ink/50">{{ $batch->id }}</td>
                        <td class="px-5 py-3 font-medium">{{ $batch->file_name }}</td>
                        <td class="px-5 py-3 text-ink/50">{{ $batch->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="px-5 py-3 text-right font-semibold">{{ $batch->total }}</td>
                        <td class="px-5 py-3 text-right text-green-700 font-semibold">{{ $batch->success }}</td>
                        <td class="px-5 py-3 text-right text-amber-600 font-semibold">{{ $batch->duplicated }}</td>
                        <td class="px-5 py-3 text-right font-semibold {{ $batch->failed > 0 ? 'text-red-600' : 'text-ink/30' }}">
                            @if ($batch->failed > 0)
                                <a href="{{ route('leads.failed') }}" class="underline">{{ $batch->failed }}</a>
                            @else 0 @endif
                        </td>
                        <td class="px-5 py-3 text-right {{ $pending > 0 ? 'text-gold-600 font-semibold' : 'text-ink/30' }}">{{ max($pending, 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-5 py-8 text-center text-ink/40">Chưa có lần import nào.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
