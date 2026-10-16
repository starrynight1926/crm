<?php

namespace Tests\Unit;

use App\Services\Ups\UpsBucketResolver;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class UpsBucketResolverTest extends TestCase
{
    private UpsBucketResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new UpsBucketResolver();
    }

    /** Parse thời điểm theo giờ Việt Nam (cutoff resolver quy về Asia/Ho_Chi_Minh). */
    private function vn(string $ts): Carbon
    {
        return Carbon::parse($ts, 'Asia/Ho_Chi_Minh');
    }

    public function test_null_checkin_returns_null(): void
    {
        $this->assertNull($this->resolver->resolveWithCutoff(null, '08:35:00'));
    }

    public function test_before_cutoff_returns_A(): void
    {
        $this->assertSame('A', $this->resolver->resolveWithCutoff($this->vn('2026-08-03 08:00:00'), '08:35:00'));
    }

    public function test_exactly_at_cutoff_returns_A(): void
    {
        $this->assertSame('A', $this->resolver->resolveWithCutoff($this->vn('2026-08-03 08:35:00'), '08:35:00'));
    }

    public function test_one_second_after_cutoff_returns_OFF(): void
    {
        $this->assertSame('OFF', $this->resolver->resolveWithCutoff($this->vn('2026-08-03 08:35:01'), '08:35:00'));
    }

    public function test_8h36_returns_OFF(): void
    {
        $this->assertSame('OFF', $this->resolver->resolveWithCutoff($this->vn('2026-08-03 08:36:00'), '08:35:00'));
    }
}
