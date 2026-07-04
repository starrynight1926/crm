<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('audit:prune {--months=12 : Giữ lại bao nhiêu tháng gần nhất}')]
#[Description('Xóa audit_logs cũ (ERD: partition/prune theo tháng) — xóa theo chunk tránh lock lâu')]
class PruneAuditLogs extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subMonths((int) $this->option('months'));
        $total = 0;

        do {
            $deleted = DB::table('audit_logs')->where('created_at', '<', $cutoff)->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Đã xóa {$total} dòng audit_logs trước {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
