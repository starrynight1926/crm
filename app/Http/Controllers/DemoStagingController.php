<?php

namespace App\Http\Controllers;

use App\Models\DemoFieldRule;
use App\Models\DemoRawLead;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Công cụ demo standalone: upload file nhiều nguồn → validate → lưu Postgres
 * (demo_raw_leads). 3 màn: upload / danh sách / báo cáo. Không đụng pipeline thật.
 */
class DemoStagingController extends Controller
{
    /** Danh tính demo: key => [tên, có phải quản lý (thấy tất cả)]. */
    public const PERSONAS = [
        'nv1' => ['name' => 'Nhân viên 1', 'manager' => false],
        'nv2' => ['name' => 'Nhân viên 2', 'manager' => false],
        'ql'  => ['name' => 'Quản lý',      'manager' => true],
    ];

    /** Danh tính demo hiện tại (key) hoặc null nếu chưa "đăng nhập demo". */
    protected function who(): ?string
    {
        $w = session('demo_user');
        return isset(self::PERSONAS[$w]) ? $w : null;
    }

    protected function isManager(): bool
    {
        $w = $this->who();
        return $w !== null && self::PERSONAS[$w]['manager'];
    }

    /** Gắn scope người-upload cho query (nhân viên chỉ thấy của mình). */
    protected function scopeOwn($query)
    {
        if (! $this->isManager()) {
            $query->where('uploaded_by', $this->who());
        }
        return $query;
    }

    // ── Đăng nhập demo (chọn nhân vật) ──────────────────────────────────
    public function loginPage()
    {
        return view('demo.login', ['personas' => self::PERSONAS]);
    }

    public function loginAs(string $who)
    {
        if (! isset(self::PERSONAS[$who])) {
            abort(404);
        }
        session(['demo_user' => $who]);
        return redirect()->route('demo.rules');
    }

    public function logout()
    {
        session()->forget('demo_user');
        return redirect()->route('demo.login');
    }

    // ── Bước 1: Quy tắc trường ──────────────────────────────────────────
    public function rules()
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }
        return view('demo.rules', [
            'rules'   => DemoFieldRule::orderByDesc('id')->get(),
            'roles'   => DemoFieldRule::ROLES,
            'persona' => self::PERSONAS[$this->who()],
        ]);
    }

    public function ruleStore(Request $request)
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }

        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'labels'          => 'required|array|min:1',
            'labels.*'        => 'nullable|string|max:60',
            'roles'           => 'array',
            'roles.*'         => 'nullable|string',
            'required'        => 'array',
        ]);

        $requiredMap = $request->input('required', []);
        $fields = [];
        foreach ($data['labels'] as $i => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $role = $data['roles'][$i] ?? '';
            $fields[] = [
                'label'    => $label,
                'role'     => in_array($role, array_keys(DemoFieldRule::ROLES), true) ? $role : '',
                'required' => isset($requiredMap[$i]),
            ];
        }

        if ($fields === []) {
            return back()->withErrors(['labels' => 'Cần ít nhất 1 trường có tên.'])->withInput();
        }

        DemoFieldRule::create([
            'name'       => $data['name'],
            'fields'     => $fields,
            'created_by' => $this->who(),
            'created_at' => now(),
        ]);

        return redirect()->route('demo.rules')->with('flash_rule', 'Đã tạo quy tắc "' . $data['name'] . '".');
    }

    public function ruleDelete(int $id)
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }
        DemoFieldRule::whereKey($id)->delete();
        return redirect()->route('demo.rules')->with('flash_rule', 'Đã xóa quy tắc.');
    }

    // ── Bước 2: Upload (chọn quy tắc) ───────────────────────────────────
    public function upload()
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }
        return view('demo.upload', [
            'rules'   => DemoFieldRule::orderByDesc('id')->get(),
            'persona' => self::PERSONAS[$this->who()],
        ]);
    }

    /**
     * Bước 1: đọc file → liệt kê các cột có trong file + preview, đoán sẵn mapping,
     * chuyển sang màn ghép cột.
     */
    public function preview(Request $request)
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }

        $request->validate([
            'rule_id' => 'required|integer',
            'file'    => 'required|file|mimes:csv,txt,xlsx,xls|max:20480',
        ]);

        $rule = DemoFieldRule::find($request->input('rule_id'));
        if (! $rule) {
            return back()->withErrors(['rule_id' => 'Quy tắc không hợp lệ.']);
        }
        $source = $rule->toSource();

        // Lưu tạm file để dùng lại ở bước import
        $stored = $request->file('file')->store('demo-imports');
        $token  = basename($stored);

        try {
            $rows = $this->readRows(storage_path('app/private/' . $stored));
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Không đọc được file: ' . $e->getMessage()]);
        }

        $headerIdx = $this->findHeaderRow($rows);
        if ($headerIdx === null) {
            return back()->withErrors(['file' => 'File rỗng hoặc không có dòng tiêu đề.']);
        }

        // Cột trong file (index => tên cột)
        $columns = [];
        foreach ($rows[$headerIdx] as $c => $cell) {
            $name = trim((string) $cell);
            $columns[$c] = $name !== '' ? $name : ('Cột ' . ($c + 1));
        }

        // 5 dòng preview sau tiêu đề
        $preview = [];
        foreach ($rows as $i => $r) {
            if ($i <= $headerIdx) continue;
            if (! array_filter($r, fn ($v) => trim((string) $v) !== '')) continue;
            $preview[] = $r;
            if (count($preview) >= 5) break;
        }

        // Đoán mapping: field->colIndex theo tên cột khớp nhãn field
        $normCols = [];
        foreach ($columns as $c => $name) {
            $normCols[$this->norm($name)] = $c;
        }
        $guess = [];
        foreach ($source['fields'] as $fi => $field) {
            $guess[$fi] = $normCols[$this->norm($field['label'])] ?? '';
        }

        return view('demo.map', [
            'source'    => $source,
            'ruleId'    => $rule->id,
            'token'     => $token,
            'headerRow' => $headerIdx,
            'columns'   => $columns,
            'preview'   => $preview,
            'guess'     => $guess,
        ]);
    }

    /**
     * Bước 2: nhận mapping người dùng chọn → áp lên từng dòng → validate → lưu.
     */
    public function import(Request $request)
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }

        $request->validate([
            'rule_id'    => 'required|integer',
            'token'      => 'required|string',
            'header_row' => 'required|integer|min:0',
            'mapping'    => 'required|array',
        ]);

        $rule = DemoFieldRule::find($request->input('rule_id'));
        if (! $rule) {
            return back()->withErrors(['rule_id' => 'Quy tắc không hợp lệ.']);
        }
        $source = $rule->toSource();

        // Chống path traversal: token chỉ gồm ký tự an toàn
        $token = $request->input('token');
        if (! preg_match('/^[A-Za-z0-9]+\.(csv|txt|xlsx|xls)$/', $token)) {
            return back()->withErrors(['file' => 'File tạm không hợp lệ.']);
        }
        $path = storage_path('app/private/demo-imports/' . $token);
        if (! is_file($path)) {
            return redirect()->route('demo.upload')->withErrors(['file' => 'File tạm đã hết hạn, tải lại giúp tao.']);
        }

        $mapping  = $request->input('mapping'); // fieldIndex => colIndex ('' = bỏ qua)
        $defaults = $request->input('defaults', []); // fieldIndex => giá trị mặc định

        // Bắt buộc map các trường role name + phone
        foreach ($source['fields'] as $fi => $field) {
            $role = $field['role'] ?? null;
            if (in_array($role, ['name', 'phone'], true) && ($mapping[$fi] ?? '') === '') {
                return back()->withErrors(['mapping' => 'Bắt buộc ghép cột cho "' . $field['label'] . '".'])->withInput();
            }
        }

        $rows = $this->readRows($path);
        $headerIdx = (int) $request->input('header_row');

        $batchId = 'B' . now()->format('ymdHis') . strtoupper(substr(md5(uniqid()), 0, 4));
        $records = [];
        $rowNo   = 0;

        foreach ($rows as $i => $raw) {
            if ($i <= $headerIdx) continue;

            // Lấy giá trị thô từ file theo mapping
            $payload = [];
            foreach ($source['fields'] as $fi => $field) {
                $col = $mapping[$fi] ?? '';
                $payload[$field['label']] = ($col !== '' && isset($raw[(int) $col]))
                    ? trim((string) $raw[(int) $col]) : '';
            }
            // Bỏ qua dòng file rỗng hoàn toàn (xét trước khi điền mặc định)
            if (! array_filter($payload, fn ($v) => $v !== '')) continue;

            // Điền giá trị mặc định cho ô còn trống
            foreach ($source['fields'] as $fi => $field) {
                if ($payload[$field['label']] === '' && trim((string) ($defaults[$fi] ?? '')) !== '') {
                    $payload[$field['label']] = trim((string) $defaults[$fi]);
                }
            }
            $rowNo++;

            $record = $this->buildRecord($source, $payload, $batchId, $rowNo);
            $record['uploaded_by'] = $this->who();
            $records[] = $record;
        }

        @unlink($path); // dọn file tạm

        if (empty($records)) {
            return redirect()->route('demo.upload')->withErrors(['file' => 'File không có dòng dữ liệu nào sau tiêu đề.']);
        }

        DemoRawLead::insert($records);

        $ok  = collect($records)->where('status', 'valid')->count();
        $bad = count($records) - $ok;

        return redirect()->route('demo.leads', ['batch' => $batchId])->with('flash', [
            'source' => $source['name'],
            'total'  => count($records),
            'ok'     => $ok,
            'bad'    => $bad,
        ]);
    }

    /** Dòng tiêu đề = dòng có nhiều ô không rỗng nhất trong 15 dòng đầu. */
    protected function findHeaderRow(array $rows): ?int
    {
        $bestIdx = null; $bestCount = 0; $seen = 0;
        foreach ($rows as $i => $r) {
            $count = count(array_filter($r, fn ($v) => trim((string) $v) !== ''));
            if ($count > $bestCount) {
                $bestCount = $count; $bestIdx = $i;
            }
            if (++$seen >= 15) break;
        }
        return $bestCount >= 2 ? $bestIdx : null;
    }

    // ── Trang 2: Danh sách + lọc nguồn ──────────────────────────────────
    public function leads(Request $request)
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }

        $query = DemoRawLead::query()->orderByDesc('id');
        $this->scopeOwn($query);

        if ($request->filled('nguon')) {
            $query->where('nguon', $request->input('nguon'));
        }
        if ($request->filled('source_key')) {
            $query->where('source_key', $request->input('source_key'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('batch')) {
            $query->where('batch_id', $request->input('batch'));
        }
        if ($request->filled('q')) {
            $kw = $request->input('q');
            $query->where(function ($w) use ($kw) {
                $w->where('ho_ten', 'ilike', "%{$kw}%")
                  ->orWhere('so_dien_thoai', 'ilike', "%{$kw}%");
            });
        }

        return view('demo.list', [
            'rows'      => $query->paginate(25)->withQueryString(),
            'rules'     => DemoFieldRule::orderByDesc('id')->get(),
            'nguonList' => $this->scopeOwn(DemoRawLead::query())->whereNotNull('nguon')->where('nguon', '!=', '')
                                ->distinct()->orderBy('nguon')->pluck('nguon'),
            'filters'   => $request->only(['nguon', 'source_key', 'status', 'q', 'batch']),
            'persona'   => self::PERSONAS[$this->who()],
            'isManager' => $this->isManager(),
        ]);
    }

    // ── Trang 3: Báo cáo ─────────────────────────────────────────────────
    public function report()
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }

        // Factory tạo query đã scope theo người-upload (nhân viên: của mình; QL: tất cả)
        $q = fn () => $this->scopeOwn(DemoRawLead::query());

        $total   = $q()->count();
        $valid   = $q()->where('status', 'valid')->count();
        $invalid = $total - $valid;

        $byNguon = $q()
            ->selectRaw("COALESCE(NULLIF(nguon, ''), '(trống)') as k, count(*) as c,
                         count(*) filter (where status = 'valid') as ok,
                         count(*) filter (where status = 'invalid') as bad")
            ->groupBy('k')->orderByDesc('c')->get();

        $bySource = $q()
            ->selectRaw('source_name as k, count(*) as c,
                         count(*) filter (where status = \'valid\') as ok,
                         count(*) filter (where status = \'invalid\') as bad')
            ->groupBy('source_name')->orderByDesc('c')->get();

        $byDay = $q()
            ->whereNotNull('ngay')
            ->selectRaw('ngay as d, count(*) as c')
            ->groupBy('ngay')->orderByDesc('ngay')->limit(30)->get();

        $errorReasons = $q()
            ->where('status', 'invalid')
            ->selectRaw('error_reason as k, count(*) as c')
            ->groupBy('error_reason')->orderByDesc('c')->get();

        $persona = self::PERSONAS[$this->who()];

        return view('demo.report', compact('total', 'valid', 'invalid', 'byNguon', 'bySource', 'byDay', 'errorReasons', 'persona'));
    }

    // ── Reset ────────────────────────────────────────────────────────────
    public function reset()
    {
        DemoRawLead::query()->getConnection()->table('demo_raw_leads')->truncate();
        return redirect()->route('demo.upload')->with('reset', true);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Đọc mọi dòng của file (csv/xlsx) thành mảng 2 chiều [rowIdx][colIdx]. */
    protected function readRows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true); // bỏ style/format cho nhẹ + né lỗi
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $maxRow = $sheet->getHighestDataRow();
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $out = [];
        for ($r = 1; $r <= $maxRow; $r++) {
            $rowArr = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
                $rowArr[] = $this->cellValue($sheet->getCell($coord));
            }
            $out[] = $rowArr;
        }

        return $out;
    }

    /**
     * Giá trị 1 ô — KHÔNG chạy engine tính công thức (né crash):
     * ô công thức → lấy giá trị cache; nếu không có, bóc fallback từ
     * IFERROR(...,"X") (kiểu Google Sheets __xludf.DUMMYFUNCTION); cuối cùng mới trả formula thô.
     */
    protected function cellValue($cell)
    {
        $v = $cell->getValue();

        if (is_string($v) && str_starts_with($v, '=')) {
            $cached = $cell->getOldCalculatedValue(); // giá trị cache lưu sẵn trong file
            if ($cached !== null && $cached !== '') {
                return $this->unwrapDummy($cached);
            }
            return $this->unwrapDummy($v);
        }

        return is_string($v) ? $this->unwrapDummy($v) : $v;
    }

    /**
     * Bóc giá trị thật khỏi công thức Google Sheets export:
     * =IFERROR(__xludf.DUMMYFUNCTION(...),"giá trị thật") → "giá trị thật".
     * Nếu không khớp pattern thì trả nguyên chuỗi.
     */
    protected function unwrapDummy(string $v): string
    {
        if (! str_contains($v, '__xludf.DUMMYFUNCTION') && ! str_starts_with($v, '=IFERROR')) {
            return $v;
        }
        // lấy chuỗi trong ngoặc kép cuối cùng trước dấu ) đóng của IFERROR
        if (preg_match('/,\s*"((?:[^"]|"")*)"\s*\)\s*$/su', trim($v), $m)) {
            return str_replace('""', '"', $m[1]);
        }
        return $v;
    }

    /**
     * Tìm dòng tiêu đề khớp nhất với bộ trường của nguồn.
     * Trả [rowIndex|null, colMap(colIndex => label)].
     */
    protected function locateHeader(array $rows, array $fields): array
    {
        $labels = array_map(fn ($f) => $this->norm($f['label']), $fields);

        $bestIdx = null; $bestMap = []; $bestScore = 0;

        foreach ($rows as $idx => $row) {
            $map = [];
            $score = 0;
            foreach ($row as $c => $cell) {
                $n = $this->norm((string) $cell);
                if ($n === '') {
                    continue;
                }
                $pos = array_search($n, $labels, true);
                if ($pos !== false) {
                    $map[$c] = $fields[$pos]['label'];
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score; $bestIdx = $idx; $bestMap = $map;
            }
        }

        // cần khớp tối thiểu 60% số cột (hoặc ít nhất 2) để coi là tiêu đề đúng
        $need = max(2, (int) ceil(count($fields) * 0.6));
        if ($bestScore < $need) {
            return [null, []];
        }
        return [$bestIdx, $bestMap];
    }

    /** Dựng 1 record đầy đủ: extract role fields + validate. */
    protected function buildRecord(array $source, array $payload, string $batchId, int $rowNo): array
    {
        // gom nhãn theo role
        $roleLabel = [];
        foreach ($source['fields'] as $f) {
            if (! empty($f['role'])) {
                $roleLabel[$f['role']] = $f['label'];
            }
        }

        $name   = isset($roleLabel['name'])   ? ($payload[$roleLabel['name']]   ?? '') : '';
        $phone  = isset($roleLabel['phone'])  ? ($payload[$roleLabel['phone']]  ?? '') : '';
        $nguon  = isset($roleLabel['source']) ? ($payload[$roleLabel['source']] ?? '') : '';
        $dateEx = isset($roleLabel['date'])   ? ($payload[$roleLabel['date']]   ?? '') : '';

        $errors = [];

        // trường bắt buộc
        foreach ($source['fields'] as $f) {
            if (! empty($f['required']) && ($payload[$f['label']] ?? '') === '') {
                $errors[] = 'Thiếu ' . $f['label'];
            }
        }

        // chuẩn hóa SĐT
        $normPhone = null;
        if ($phone !== '') {
            $normPhone = Lead::normalizePhone($phone);
            if ($normPhone === null) {
                $errors[] = 'SĐT không hợp lệ';
            }
        }

        return [
            'batch_id'       => $batchId,
            'source_key'     => $source['key'],
            'source_name'    => $source['name'],
            'payload'        => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ho_ten'         => $name !== '' ? mb_substr($name, 0, 255) : null,
            'so_dien_thoai'  => $normPhone,
            'nguon'          => $nguon !== '' ? mb_substr($nguon, 0, 255) : null,
            'ngay'           => $this->parseDate($dateEx),
            'status'         => empty($errors) ? 'valid' : 'invalid',
            'error_reason'   => empty($errors) ? null : implode('; ', $errors),
            'row_no'         => $rowNo,
            'created_at'     => now(),
        ];
    }

    /** Parse ngày: serial Excel, d/m/Y, d/m, Y-m-d, Y-m-d H:i:s... */
    protected function parseDate($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        // Serial Excel (số, có thể kèm phần thập phân giờ)
        if (is_numeric($value)) {
            $num = (float) $value;
            if ($num > 20000 && $num < 90000) { // khoảng ngày hợp lý (1954–2146)
                try {
                    return ExcelDate::excelToDateTimeObject($num)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }
            return null; // số nhỏ (vd 0.375 = giờ) → không phải ngày
        }
        foreach (['d/m/Y', 'd/m/y', 'Y-m-d H:i:s', 'Y-m-d', 'd-m-Y', 'd.m.Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $value);
                if ($d !== false) {
                    return $d->format('Y-m-d');
                }
            } catch (\Throwable) {
                // thử format kế tiếp
            }
        }
        // d/m không năm → gán năm hiện tại
        if (preg_match('#^(\d{1,2})/(\d{1,2})$#', $value, $m)) {
            try {
                return Carbon::create(now()->year, (int) $m[2], (int) $m[1])->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /** Chuẩn hóa chuỗi để so khớp tiêu đề (bỏ khoảng trắng thừa, hạ thường). */
    protected function norm(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s));
        return mb_strtolower($s);
    }
}
