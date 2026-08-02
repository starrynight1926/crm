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

        $this->info('→ Drop schema public bên pgsql...');
        try {
            DB::connection('pgsql')->unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
            $this->info('  pgsql wiped.');
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
