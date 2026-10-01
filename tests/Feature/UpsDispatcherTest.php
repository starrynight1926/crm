<?php

namespace Tests\Feature;

use App\Models\DailyAttendance;
use App\Models\PoolUnit;
use App\Models\User;
use App\Services\Ups\UpsDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private PoolUnit $facility;
    private UpsDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'Asia/Ho_Chi_Minh'));

        $root = PoolUnit::createNode(['name' => 'Cty', 'code' => 'p-root', 'kind' => 'company']);
        $branch = PoolUnit::createNode(['name' => 'HN', 'code' => 'p-hn', 'kind' => 'branch'], $root);
        $this->facility = PoolUnit::createNode(['name' => 'CS1', 'code' => 'p-cs1', 'kind' => 'facility'], $branch);

        $this->dispatcher = app(UpsDispatcher::class);
    }

    private function attend(string $bucket, string $time = '08:00:00'): User
    {
        $u = User::factory()->create();
        DailyAttendance::create([
            'facility_pool_unit_id' => $this->facility->id,
            'user_id' => $u->id,
            'work_date' => now()->toDateString(),
            'checkin_at' => Carbon::parse('2026-08-03 '.$time, 'Asia/Ho_Chi_Minh'),
            'list_bucket' => $bucket,
        ]);

        return $u;
    }

    public function test_pick_mkt_returns_null_when_list_empty(): void
    {
        $this->assertNull($this->dispatcher->pickMkt($this->facility->id));
    }

    public function test_pick_mkt_round_robin(): void
    {
        $s1 = $this->attend('MKT', '08:00:00');
        $s2 = $this->attend('MKT', '08:05:00');
        $s3 = $this->attend('MKT', '08:10:00');

        $this->assertSame($s1->id, $this->dispatcher->pickMkt($this->facility->id)->id);
        $this->assertSame($s2->id, $this->dispatcher->pickMkt($this->facility->id)->id);
        $this->assertSame($s3->id, $this->dispatcher->pickMkt($this->facility->id)->id);
        // wrap around
        $this->assertSame($s1->id, $this->dispatcher->pickMkt($this->facility->id)->id);
    }

    public function test_pick_greet_prefers_A_then_B_then_C_then_OFF(): void
    {
        $a = $this->attend('A', '08:00:00');
        $b = $this->attend('B', '08:00:00');
        $c = $this->attend('C', '08:00:00');
        $off = $this->attend('OFF', '09:00:00');

        $this->assertSame($a->id, $this->dispatcher->pickGreet($this->facility->id)->id);
        // A vẫn còn (nhưng chỉ có 1) → round-robin quay lại a
        $this->assertSame($a->id, $this->dispatcher->pickGreet($this->facility->id)->id);
    }

    public function test_pick_greet_skips_busy_sale_and_falls_through_bucket(): void
    {
        $a = $this->attend('A', '08:00:00');
        $b = $this->attend('B', '08:00:00');

        $this->dispatcher->markBusy($a->id);
        // A bận → fallthrough sang B
        $this->assertSame($b->id, $this->dispatcher->pickGreet($this->facility->id)->id);

        $this->dispatcher->markFree($a->id);
        // A rảnh lại → ưu tiên A
        $this->assertSame($a->id, $this->dispatcher->pickGreet($this->facility->id)->id);
    }

    public function test_pick_greet_returns_null_when_all_busy(): void
    {
        $a = $this->attend('A', '08:00:00');
        $off = $this->attend('OFF', '09:00:00');

        $this->dispatcher->markBusy($a->id);
        $this->dispatcher->markBusy($off->id);

        $this->assertNull($this->dispatcher->pickGreet($this->facility->id));
    }
}
