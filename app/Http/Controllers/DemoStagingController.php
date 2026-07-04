<?php

namespace App\Http\Controllers;

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
        return redirect()->route('demo.leads');
    }

    public function logout()
    {
        session()->forget('demo_user');
        return redirect()->route('demo.login');
    }

    // ── Trang 1: Upload ──────────────────────────────────────────────────
    public function upload()
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }
        return view('demo.upload', [
            'sources' => DemoRawLead::sources(),
            'persona' => self::PERSONAS[$this->who()],
        ]);
    }

    public function store(Request $request)
    {
        if (! $this->who()) {
            return redirect()->route('demo.login');
        }

        $request->validate([
            'source_key' => 'required|string',
            'file'       => 'required|file|mimes:csv,txt,xlsx,xls|max:20480',
        ]);

        $source = DemoRawLead::source($request->input('source_key'));
        if (! $source) {
            return back()->withErrors(['source_key' => 'Nguồn không hợp lệ.']);
        }

        try {
            $rows = $this->readRows($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Không đọc được file: ' . $e->getMessage()]);
        }

        [$headerIdx, $colMap] = $this->locateHeader($rows, $source['fields']);
        if ($headerIdx === null) {
            return back()->withErrors([
                'file' => 'Không tìm thấy dòng tiêu đề khớp với "' . $source['name'] . '". Kiểm tra lại file/chọn đúng nguồn.',
            ]);
        }

        $batchId = 'B' . now()->format('ymdHis') . strtoupper(substr(md5(uniqid()), 0, 4));
        $records = [];
        $rowNo   = 0;

        foreach ($rows as $i => $raw) {
            if ($i <= $headerIdx) {
                continue; // bỏ qua đến hết dòng tiêu đề
            }
            // map cột theo header đã xác định
            $payload = [];
            foreach ($colMap as $colIndex => $label) {
                $payload[$label] = isset($raw[$colIndex]) ? trim((string) $raw[$colIndex]) : '';
            }
            // dòng rỗng hoàn toàn → dừng khối (1 file = 1 mẫu)
            if (! array_filter($payload, fn ($v) => $v !== '')) {
                continue;
            }
            $rowNo++;

            $record = $this->buildRecord($source, $payload, $batchId, $rowNo);
            $record['uploaded_by'] = $this->who();
            $records[] = $record;
        }

        if (empty($records)) {
            return back()->withErrors(['file' => 'File không có dòng dữ liệu nào sau tiêu đề.']);
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
            'sources'   => DemoRawLead::sources(),
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
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        // getCalculatedValue để lấy giá trị thô; ta xử lý ngày riêng theo role.
        return $sheet->toArray(null, true, false, false);
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
