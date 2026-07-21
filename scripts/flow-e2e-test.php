<?php
// E2E test: page → booking → sale → đặt booking → check comment sau khi booked.
// Chạy: php scripts/flow-e2e-test.php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Models\LeadStatusLog;
use App\Models\AuditLog;
use App\Services\DistributionEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$results = [];
$mark = function ($case, $ok, $note = '') use (&$results) {
    $results[] = ($ok ? '✅' : '❌') . " {$case}" . ($note ? " — {$note}" : '');
    echo end($results) . "\n";
};
$actAs = function (string $email) {
    Auth::logout();
    $u = User::firstWhere('email', $email);
    if (!$u) throw new RuntimeException("User $email không tồn tại");
    Auth::login($u);
    return $u;
};

// Dọn lead test cũ
Lead::where('phone', '0999888777')->forceDelete();

$engine = app(DistributionEngine::class);

// -------------------------------------------------------------------
// 1) NHÂN VIÊN TRỰC PAGE tạo lead mới → mặc định vào kho chung công ty
// -------------------------------------------------------------------
echo "\n──── STEP 1: page1@ tạo lead ────\n";
$page = $actAs('page1@longevity.com.vn');
$mark("page1 có perm lead.create", $page->hasPermission('lead.create'));

$lead = Lead::create([
    'name' => 'Khách E2E Test',
    'phone' => '0999888777',
    'received_date' => now()->toDateString(),
    'source_group' => Lead::SOURCE_MARKETING,
    'classification' => 'new',
    'pool_level' => Lead::POOL_COMMON,
    'owner_id' => null,
    'receiver_id' => $page->id,
    'org_unit_id' => null,
    'approval_status' => 'none',
]);
$lead->generateCode();
$mark("Lead tạo được (id={$lead->id})", (bool) $lead->id);
$mark("Mã KH sinh đúng (có -MKT)", str_contains($lead->code, '-MKT'), $lead->code);
$mark("Lead ở kho chung công ty", $lead->pool_level === 'common' && $lead->org_unit_id === null);
$mark("page1 THẤY lead của mình (self scope)", $lead->isVisibleTo($page));

// -------------------------------------------------------------------
// 2) CM booking chia lead vào team-giang-booking
// -------------------------------------------------------------------
echo "\n──── STEP 2: cmbk@ chia lead sang team booking ────\n";
$cmbk = $actAs('cmbk@longevity.com.vn');
$mark("cmbk có perm distribute_booking", $cmbk->hasPermission('lead.distribute_booking'));
$mark("cmbk THẤY lead kho chung", $lead->refresh()->isVisibleTo($cmbk));

$teamBooking = OrgUnit::firstWhere('code', 'team-giang-booking');
$lead->update(['pool_level' => 'team', 'org_unit_id' => $teamBooking->id]);
$mark("Lead chuyển vào team booking (pool=team, org={$teamBooking->id})",
    $lead->fresh()->pool_level === 'team' && $lead->fresh()->org_unit_id === $teamBooking->id);

// -------------------------------------------------------------------
// 3) TEAM BOOKING (book1@) điền info + thêm note
// -------------------------------------------------------------------
echo "\n──── STEP 3: book1@ team booking điền info ────\n";
$book1 = $actAs('book1@longevity.com.vn');
$mark("book1 có perm lead.update", $book1->hasPermission('lead.update'));
$mark("book1 THẤY lead team-giang-booking", $lead->refresh()->isVisibleTo($book1));

$lead->update(['classification' => 'follow', 'status_1' => 'Đã gọi, khách quan tâm']);
LeadStatusLog::record($lead, 'note', null, 'Booking đã liên hệ, khách hẹn tuần sau', $book1->id);
$mark("book1 update classification=follow", $lead->fresh()->classification === 'follow');
$mark("book1 add note thành công",
    LeadStatusLog::where('lead_id', $lead->id)->where('user_id', $book1->id)->exists());

// -------------------------------------------------------------------
// 4) CM sale chia lead sang team-giang-sale
// -------------------------------------------------------------------
echo "\n──── STEP 4: cmsale@ chia sang team sale ────\n";
$cmsale = $actAs('cmsale@longevity.com.vn');
$mark("cmsale có perm distribute_sale", $cmsale->hasPermission('lead.distribute_sale'));

$teamSale = OrgUnit::firstWhere('code', 'team-giang-sale');
$lead->update(['pool_level' => 'team', 'org_unit_id' => $teamSale->id]);
$mark("Lead chuyển vào team sale (org={$teamSale->id})",
    $lead->fresh()->org_unit_id === $teamSale->id);

// -------------------------------------------------------------------
// 5) TEAM SALE (nvkd@) nhận, điền info sale
// -------------------------------------------------------------------
echo "\n──── STEP 5: nvkd@ sale điền info ────\n";
$sale = $actAs('nvkd@longevity.com.vn');
$mark("nvkd có perm lead.update", $sale->hasPermission('lead.update'));
$mark("nvkd có perm lead.consult (là chuyên viên tư vấn)", $sale->hasPermission('lead.consult'));
$mark("nvkd THẤY lead team-giang-sale", $lead->refresh()->isVisibleTo($sale));

// Sale nhận lead vào kho cá nhân
$lead->update(['owner_id' => $sale->id, 'pool_level' => 'personal']);
$lead->update(['service_name' => 'Y học Phương Đông', 'potential_service' => 'BJR (1 vùng)']);
LeadStatusLog::record($lead, 'note', null, 'Sale đã tư vấn, khách đồng ý gặp bác sĩ', $sale->id);
$mark("nvkd nhận lead (owner_id)", $lead->fresh()->owner_id === $sale->id);
$mark("nvkd điền service_name", $lead->fresh()->service_name === 'Y học Phương Đông');

// -------------------------------------------------------------------
// 6) Đặt BOOKING sang lara-sbooking (simulate callback)
// -------------------------------------------------------------------
echo "\n──── STEP 6: đặt booking sang bên booking ────\n";
$before = $lead->fresh()->booking_status;
DB::transaction(function () use ($lead, $before) {
    $lead->fill([
        'booking_status' => Lead::BOOKING_BOOKED,
        'booking_ma'     => 'BKG-260720-E2E01',
        'booked_at'      => now(),
    ])->save();
    AuditLog::record('booking_created', $lead, [
        'booking_ma' => 'BKG-260720-E2E01',
        'booking_id' => 999,
        'booking_status_before' => $before,
    ]);
});
$lead->refresh();
$mark("booking_status = booked", $lead->booking_status === Lead::BOOKING_BOOKED);
$mark("booking_ma lưu đúng", $lead->booking_ma === 'BKG-260720-E2E01');
$mark("booked_at có giá trị", $lead->booked_at !== null);
$mark("AuditLog booking_created ghi lại",
    AuditLog::where('action', 'booking_created')->where('entity_type', 'Lead')->where('entity_id', $lead->id)->exists());

// -------------------------------------------------------------------
// 7) SAU KHI BOOKED — sale + booking còn add note/comment được không?
// -------------------------------------------------------------------
echo "\n──── STEP 7: sau khi booked, sale + booking còn comment không? ────\n";

$book1 = $actAs('book1@longevity.com.vn');
$book1Visible = $lead->refresh()->isVisibleTo($book1);
$mark("book1 (past handler) VẪN thấy lead sau khi lead sang team sale", $book1Visible);
$isPast = $lead->isPastHandlerFor($book1);
$mark("book1 được đánh dấu là past handler", $isPast);
// book1 add note được (canAddNote)
if ($book1Visible) {
    LeadStatusLog::record($lead, 'note', null, 'Booking follow up sau khi booked', $book1->id);
    $mark("book1 add note thành công sau booked",
        LeadStatusLog::where('lead_id', $lead->id)->where('user_id', $book1->id)->count() >= 2);
}
// Simulate lead-detail permission check for past handler
$book1Fresh = $lead->fresh();
$book1IsPastOnly = ! ($book1Fresh->owner_id === $book1->id || $book1Fresh->receiver_id === $book1->id)
    && ! ($book1Fresh->pool_level === Lead::POOL_TEAM && in_array($book1Fresh->org_unit_id, $book1->memberOrgUnitIds(), true))
    && ! ($book1Fresh->org_unit_id !== null && $book1->canSeeOrgUnit($book1Fresh->org_unit_id))
    && $book1Fresh->isPastHandlerFor($book1);
$mark("book1 là past-handler-only (không thuộc scope hiện tại)", $book1IsPastOnly);
// past handler KHÔNG sửa được field khác — simulate check via canEditLead logic
// canEdit = has update perm && visible && ! isPastHandlerOnly → false với past handler
$book1CanFullyEdit = $book1->hasPermission('lead.update') && $book1Visible && ! $book1IsPastOnly;
$mark("book1 KHÔNG sửa được field khác ngoài note (chỉ past handler)", ! $book1CanFullyEdit);

$sale = $actAs('nvkd@longevity.com.vn');
$saleCanEdit = $sale->hasPermission('lead.update') && $lead->refresh()->isVisibleTo($sale);
$mark("nvkd canEditLead sau booked", $saleCanEdit);
if ($saleCanEdit) {
    LeadStatusLog::record($lead, 'note', null, 'Sale follow up sau khi booked', $sale->id);
    $mark("nvkd add note thành công sau booked",
        LeadStatusLog::where('lead_id', $lead->id)->where('user_id', $sale->id)->count() >= 2);
}

// -------------------------------------------------------------------
// 8) Summary
// -------------------------------------------------------------------
echo "\n════════════ SUMMARY ════════════\n";
$fails = array_filter($results, fn ($r) => str_starts_with($r, '❌'));
echo count($results) . " check | " . (count($results) - count($fails)) . " ✅ | " . count($fails) . " ❌\n";
if ($fails) {
    echo "\nFAILS:\n";
    foreach ($fails as $f) echo $f . "\n";
    exit(1);
}
echo "ALL PASSED.\n";
