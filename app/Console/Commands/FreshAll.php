<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Reset toàn bộ dữ liệu (mysql + pgsql) rồi migrate:fresh --seed.
 * migrate:fresh mặc định chỉ drop connection default (mysql) — cần dọn pgsql riêng
 * vì raw pipeline dùng connection 'pgsql'.
 *
 * Usage: php artisan db:fresh-all [--seed]
 */
class FreshAll extends Command
{
    protected $signature = 'db:fresh-all {--seed : chạy DatabaseSeeder sau khi fresh}';

    protected $description = 'Drop cả mysql + pgsql rồi chạy migrate:fresh (+seed nếu --seed)';

    public function handle(): int
    {
        if (! $this->option('no-interaction') && ! $this->confirm('XÓA TOÀN BỘ DATA cả mysql + pgsql? Không hoàn tác được.', false)) {
            $this->info('Huỷ.');
            return self::SUCCESS;
        }

        $this->info('→ Drop từng table pgsql (shared host thường không có DROP SCHEMA)...');
        try {
            $pg = DB::connection('pgsql');
            // Thử DROP SCHEMA trước — chỉ chạy được nếu user là owner.
            try {
                $pg->unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
                $this->info('  pgsql: DROP SCHEMA OK.');
            } catch (\Throwable) {
                // Fallback: drop từng bảng.
                $tables = $pg->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                if (! empty($tables)) {
                    $names = array_map(fn ($t) => '"' . $t->tablename . '"', $tables);
                    $pg->unprepared('DROP TABLE IF EXISTS ' . implode(', ', $names) . ' CASCADE;');
                    $this->info('  pgsql: đã drop ' . count($tables) . ' bảng.');
                } else {
                    $this->info('  pgsql: không có bảng nào để drop.');
                }
                // Xóa cả migration history bên pgsql nếu có bảng migrations.
                if (in_array('migrations', array_map(fn ($t) => $t->tablename, $tables), true)) {
                    // Đã drop ở trên, skip.
                }
            }
        } catch (\Throwable $e) {
            $this->warn('  pgsql skip: ' . $e->getMessage());
        }

        $this->info('→ migrate:fresh --force (mysql default)...');
        Artisan::call('migrate:fresh', ['--force' => true], $this->getOutput());

        if ($this->option('seed')) {
            $this->info('→ db:seed --force...');
            Artisan::call('db:seed', ['--force' => true], $this->getOutput());
        }

        $this->info('✓ Xong. Cả 2 DB đã reset + migrate.' . ($this->option('seed') ? ' Seed đã chạy.' : ''));
        return self::SUCCESS;
    }
}
