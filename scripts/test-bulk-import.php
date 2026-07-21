<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessRawLead;
use Illuminate\Support\Facades\DB;

DB::connection('pgsql')->table('raw_leads')->delete();
DB::connection('pgsql')->table('import_batches')->delete();

$csv = __DIR__ . '/../scripts/test-import.csv';
if (!file_exists($csv)) {
    copy('C:/Users/admin/AppData/Local/Temp/claude/F--Laragon-www-lara-scrm/7f1543a3-3a5d-497c-a8fd-61fe08316d1d/scratchpad/test-import.csv', $csv);
}

$mapping = [0=>'name',1=>'phone',2=>'received_date',3=>'page',4=>'camp',5=>'link',6=>'insight'];

$batchId = DB::connection('pgsql')->table('import_batches')->insertGetId([
    'file_name'      => 'test-import.csv',
    'uploaded_by'    => 1,
    'column_mapping' => json_encode($mapping),
    'total'          => 0, 'success' => 0, 'failed' => 0, 'duplicated' => 0,
    'created_at'     => now(),
]);
echo "batch id=$batchId\n";

$fh = fopen($csv, 'r');
fgetcsv($fh);
$rows = 0;
while (($row = fgetcsv($fh)) !== false) {
    $payload = [];
    foreach ($mapping as $col => $key) {
        $payload[$key] = $row[$col] ?? null;
    }
    $rawId = DB::connection('pgsql')->table('raw_leads')->insertGetId([
        'source_type'     => 'csv',
        'import_batch_id' => $batchId,
        'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'status'          => 'pending',
        'created_at'      => now(),
    ]);
    ProcessRawLead::dispatch($rawId);
    $rows++;
}
fclose($fh);
DB::connection('pgsql')->table('import_batches')->where('id', $batchId)->update(['total' => $rows]);
echo "queued $rows jobs (batch $batchId)\n";
