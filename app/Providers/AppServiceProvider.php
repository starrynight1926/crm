<?php

namespace App\Providers;

use App\Support\PublicLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 2026-08-02: override config services.booking từ AppSetting (UI /settings/booking-connection)
        // để user sửa token/URL không cần đụng .env. Bọc try/catch — bootstrap có thể chạy trước migration.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('app_settings')) {
                if ($url = \App\Models\AppSetting::get('booking_url')) {
                    config(['services.booking.url' => $url, 'services.booking.api_url' => rtrim($url, '/') . '/api']);
                }
                if ($token = \App\Models\AppSetting::get('booking_api_token')) {
                    config(['services.booking.api_token' => $token]);
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        // 2026-08-04: `migrate:fresh` mặc định chỉ drop connection default (mysql).
        // PgSQL còn giữ `raw_leads` + `custom_fields` — không wipe thì bảng cũ chặn migration
        // pgsql chạy lại. Hook để hỏi trước khi reset PG.
        // Public log: đăng nhập / đăng xuất + set/xóa cookie gate `scrm_authed`.
        Event::listen(Login::class, function (Login $e) {
            PublicLog::write('đăng nhập');
            // Cookie gate /logs.md — CHỈ set cho username 'admin' (super-admin duy nhất).
            if (($e->user->username ?? null) === 'admin') {
                Cookie::queue('scrm_authed', '1', 60 * 24 * 365);
            }
        });
        Event::listen(Logout::class, function (Logout $e) {
            PublicLog::write('đăng xuất');
            if (($e->user?->username ?? null) === 'admin') {
                Cookie::queue(Cookie::forget('scrm_authed'));
            }
        });

        // Public log: lead create/update/delete.
        \App\Models\Lead::created(function ($lead) {
            PublicLog::write('tạo lead', "lead #{$lead->id} " . ($lead->name ?? '(chưa tên)'));
        });
        \App\Models\Lead::deleted(function ($lead) {
            PublicLog::write('xóa lead', "lead #{$lead->id} " . ($lead->name ?? ''));
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command !== 'migrate:fresh') {
                return;
            }
            if (! array_key_exists('pgsql', config('database.connections', []))) {
                return;
            }

            $output = $event->output;
            $input = $event->input;

            // No-interaction (test / CI / --no-interaction) → default reset để không chặn.
            $shouldReset = true;
            if ($input->isInteractive()) {
                $helper = new \Symfony\Component\Console\Helper\QuestionHelper();
                $question = new \Symfony\Component\Console\Question\Question(
                    "\n<comment>[migrate:fresh]</comment> PgSQL còn giữ raw_leads + custom_fields. Reset luôn?\n"
                    . "  <info>0</info> = bỏ qua pgsql\n"
                    . "  <info>1</info> = reset pgsql (mặc định)\n"
                    . " > ",
                    '1'
                );
                $question->setValidator(function ($answer) {
                    $a = trim((string) $answer);
                    if (! in_array($a, ['0', '1'], true)) {
                        throw new \RuntimeException('Chỉ được nhập 0 hoặc 1.');
                    }
                    return $a;
                });
                $shouldReset = $helper->ask($input, $output, $question) === '1';
            }

            if (! $shouldReset) {
                $output->writeln('<comment>[migrate:fresh]</comment> Bỏ qua wipe pgsql.');
                return;
            }

            try {
                Artisan::call('db:wipe', ['--database' => 'pgsql', '--force' => true]);
                $output->writeln('<info>[migrate:fresh]</info> Đã wipe pgsql.');
            } catch (\Throwable $e) {
                // PG có thể không kết nối được ở env dev — log vào output, không chặn fresh.
                $output->writeln("<error>[migrate:fresh]</error> Bỏ qua wipe pgsql: {$e->getMessage()}");
            }
        });
    }
}
