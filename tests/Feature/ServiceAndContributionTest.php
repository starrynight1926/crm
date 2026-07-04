<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\CustomerService;
use App\Models\CustomerServicePhase;
use App\Models\Lead;
use App\Models\LeadStatusLog;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\ContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ServiceAndContributionTest extends TestCase
{
    use RefreshDatabase;

    private Lead $lead;
    private Service $service;
    private User $saleA;
    private User $saleB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saleA = User::factory()->create(['name' => 'Sale A']);
        $this->saleB = User::factory()->create(['name' => 'Sale B']);

        $this->lead = Lead::create([
            'received_date' => now()->toDateString(),
            'name' => 'Khách dịch vụ',
            'phone' => '0905550001',
            'receiver_id' => $this->saleA->id,
            'owner_id' => $this->saleB->id,
        ]);

        // Dịch vụ da liễu 10 phase, giá theo phase
        $this->service = Service::create(['name' => 'Da liễu', 'code' => 'DL', 'pricing_type' => 'per_phase']);
        for ($i = 1; $i <= 10; $i++) {
            $this->service->phases()->create(['position' => $i, 'name' => "Phase $i", 'phase_price' => 1_000_000]);
        }
    }

    private function attach(): CustomerService
    {
        $cs = CustomerService::create([
            'lead_id' => $this->lead->id,
            'service_id' => $this->service->id,
            'agreed_price' => 9_000_000, // giá chốt override niêm yết 10tr
            'started_at' => now(),
        ]);
        $cs->initPhases();

        return $cs;
    }

    // ---------- Dịch vụ & phase ----------

    public function test_list_price_per_phase_sums_phases(): void
    {
        $this->assertSame(10_000_000, $this->service->listPrice());
    }

    public function test_init_phases_creates_all_pending(): void
    {
        $cs = $this->attach();

        $this->assertSame(10, $cs->phases()->count());
        $this->assertSame(0, $cs->doneCount());
    }

    public function test_handover_case_a_does_3_phases_then_b_continues(): void
    {
        // Case điển hình scope.md 8.1: A làm 3/10, bàn giao B
        $cs = $this->attach();

        $cs->phases()->orderBy('id')->limit(3)->get()->each(fn (CustomerServicePhase $p) => $p->update([
            'status' => 'done',
            'done_by' => $this->saleA->id,
            'done_at' => now(),
            'handover_note' => 'Da nhạy cảm, dùng liệu trình nhẹ',
        ]));

        $this->assertSame(3, $cs->doneCount());

        // B đọc được lịch sử + note của A
        $history = $cs->phases()->where('status', 'done')->with('doneBy')->get();
        $this->assertTrue($history->every(fn ($p) => $p->doneBy->id === $this->saleA->id));
        $this->assertSame('Da nhạy cảm, dùng liệu trình nhẹ', $history->first()->handover_note);

        // B làm tiếp phase 4
        $phase4 = $cs->phases()->where('status', 'pending')->orderBy('id')->first();
        $phase4->update(['status' => 'done', 'done_by' => $this->saleB->id, 'done_at' => now()]);

        $this->assertSame(4, $cs->doneCount());
        $this->assertSame(2, $cs->phases()->where('status', 'done')->distinct()->count('done_by'));
    }

    // ---------- Thanh toán & công nợ ----------

    public function test_outstanding_is_agreed_minus_paid(): void
    {
        $cs = $this->attach();

        Payment::create(['lead_id' => $this->lead->id, 'customer_service_id' => $cs->id, 'amount' => 3_000_000, 'paid_at' => now(), 'collected_by' => $this->saleA->id]);
        Payment::create(['lead_id' => $this->lead->id, 'customer_service_id' => $cs->id, 'amount' => 2_000_000, 'paid_at' => now(), 'collected_by' => $this->saleB->id]);

        $this->assertSame(5_000_000, $cs->totalPaid());
        $this->assertSame(4_000_000, $cs->outstanding()); // 9tr chốt - 5tr đã thu
    }

    public function test_outstanding_never_negative(): void
    {
        $cs = $this->attach();
        Payment::create(['lead_id' => $this->lead->id, 'customer_service_id' => $cs->id, 'amount' => 99_000_000, 'paid_at' => now(), 'collected_by' => $this->saleA->id]);

        $this->assertSame(0, $cs->outstanding());
    }

    // ---------- % đóng góp ----------

    public function test_save_contributions_sum_100(): void
    {
        $svc = new ContributionService();

        $svc->save($this->lead, [
            ['user_id' => $this->saleA->id, 'role_label' => 'collector', 'percent' => 30],
            ['user_id' => $this->saleB->id, 'role_label' => 'closer', 'percent' => 70],
        ], $this->saleB->id);

        $this->assertSame(2, Contribution::where('lead_id', $this->lead->id)->count());
        $this->assertEquals(100, Contribution::where('lead_id', $this->lead->id)->sum('percent'));
    }

    public function test_sum_not_100_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ContributionService())->save($this->lead, [
            ['user_id' => $this->saleA->id, 'role_label' => 'collector', 'percent' => 30],
            ['user_id' => $this->saleB->id, 'role_label' => 'closer', 'percent' => 60],
        ], $this->saleB->id);
    }

    public function test_duplicate_user_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ContributionService())->save($this->lead, [
            ['user_id' => $this->saleA->id, 'role_label' => 'collector', 'percent' => 50],
            ['user_id' => $this->saleA->id, 'role_label' => 'closer', 'percent' => 50],
        ], $this->saleB->id);
    }

    public function test_resave_overwrites_previous(): void
    {
        $svc = new ContributionService();
        $svc->save($this->lead, [['user_id' => $this->saleA->id, 'role_label' => 'closer', 'percent' => 100]], $this->saleB->id);
        $svc->save($this->lead, [
            ['user_id' => $this->saleA->id, 'role_label' => 'collector', 'percent' => 40],
            ['user_id' => $this->saleB->id, 'role_label' => 'closer', 'percent' => 60],
        ], $this->saleB->id);

        $this->assertSame(2, Contribution::where('lead_id', $this->lead->id)->count());
        $this->assertEquals(100, Contribution::where('lead_id', $this->lead->id)->sum('percent'));
    }

    public function test_suggest_participants_from_explicit_history(): void
    {
        $cs = $this->attach();

        // saleA làm 1 phase; 1 user khác chăm sóc qua status log
        $carer = User::factory()->create(['name' => 'Carer']);
        LeadStatusLog::record($this->lead, 'note', null, 'đã gọi', $carer->id);
        $cs->phases()->first()->update(['status' => 'done', 'done_by' => $this->saleA->id, 'done_at' => now()]);

        $suggested = (new ContributionService())->suggestParticipants($this->lead->fresh());
        $ids = $suggested->pluck('user.id')->all();

        $this->assertContains($this->saleA->id, $ids); // receiver + phase worker (1 dòng duy nhất)
        $this->assertContains($this->saleB->id, $ids); // owner
        $this->assertContains($carer->id, $ids);       // người chăm sóc
        $this->assertSame(count($ids), count(array_unique($ids)));
    }
}
