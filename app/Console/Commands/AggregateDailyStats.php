<?php

namespace App\Console\Commands;

use App\Services\StatsAggregator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('stats:aggregate {--from= : Ngày bắt đầu (Y-m-d), mặc định hôm nay} {--to= : Ngày kết thúc, mặc định = from}')]
#[Description('Tính lại stats_daily cho khoảng ngày (idempotent — xóa và ghi lại từng ngày)')]
class AggregateDailyStats extends Command
{
    public function handle(StatsAggregator $aggregator): int
    {
        $from = Carbon::parse($this->option('from') ?: today());
        $to = Carbon::parse($this->option('to') ?: $from);

        $days = 0;
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $aggregator->aggregateDay($date->toDateString());
            $days++;
        }

        $this->info("Đã tính lại stats_daily cho {$days} ngày ({$from->toDateString()} → {$to->toDateString()}).");

        return self::SUCCESS;
    }
}
