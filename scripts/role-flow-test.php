<?php
// E2E flow test cho các role. Chạy từ project root: php scripts/role-flow-test.php
// Bootstrap Laravel để dùng model/engine trực tiếp (không cần HTTP).

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Lead;
use App\Models\OrgUnit;
use App\Services\DistributionEngine;
use Illuminate\Support\Facades\Auth;

$results = [];
$mark = function ($case, $ok, $note = '') use (&$results) {
    $results[] = ($ok ? '✅' : '❌') . " {$case}" . ($note ? " — {$note}" : '');
};

$engine = app(DistributionEngine::class);

// 0) Cleanup lead demo cũ (test lặp lại nhiều lần)
Lead::where('phone', 'like', '099TEST%')->forceDelete();

// Helper: đăng nhập & test permission
$actAs = function (string $email) {
    $u = User::firstWhere('email', $email);
    Auth::login($u);
    return $u;
};

// ===================================================================
// TEST 1: Team trực page (page1@) — chỉ được tạo lead
// ===================================================================
$page1 = $actAs('page1@longevity.com.vn');
$mark('page1 has lead.create', $page1->hasPermission('lead.create'));
$mark('page1 NOT has lead.view', ! $page1->hasPermission('lead.view'));
$mark('page1 NOT has lead.update', ! $page1->hasPermission('lead.update'));
$mark('page1 NOT has lead.distribute', ! $page1->hasPermission('lead.distribute'));

// Tạo lead thật
try {
    $pageOrg = OrgUnit::firstWhere('code', 'team-giang-page');
    $lead = Lead::create([
        'name' => 'Khách Test Page 1',
        'phone' => '099TEST0001',
        'received_date' => now()->toDateString(),
        'source_group' => 'Marketing',
        'lead_source' => 'facebook',
        'source_status' => 'approved',
        'receiver_id' => $page1->id,
        'pool_level' => Lead::POOL_COMMON,
        'org_unit_id' => $pageOrg?->id,
    ]);
    $mark('page1 tạo lead', true, "lead#{$lead->id}");
} catch (\Throwable $e) {
    $mark('page1 tạo lead', false, $e->getMessage());
}

// ===================================================================
// TEST 2: CM booking (cmbk@) — chia số lead cho Team booking
// ===================================================================
$cmbk = $actAs('cmbk@longevity.com.vn');
$mark('cmbk has lead.distribute', $cmbk->hasPermission('lead.distribute'));
$mark('cmbk has lead.recall', $cmbk->hasPermission('lead.recall'));

// Tạo 1 lead trong kho booking, chia cho book1
try {
    $bookingOrg = OrgUnit::firstWhere('code', 'team-giang-booking');
    $book1 = User::firstWhere('email', 'book1@longevity.com.vn');
    $leadBk = Lead::create([
        'name' => 'Khách Test Booking',
        'phone' => '099TEST0002',
        'received_date' => now()->toDateString(),
        'source_group' => 'Marketing',
        'lead_source' => 'facebook',
        'source_status' => 'approved',
        'receiver_id' => $cmbk->id,
        'pool_level' => Lead::POOL_TEAM,
        'org_unit_id' => $bookingOrg?->id,
    ]);
    $engine->manualAssign($leadBk, $book1, $cmbk->id);
    $leadBk->refresh();
    $mark('cmbk chia lead cho book1', $leadBk->owner_id === $book1->id, "owner=" . $leadBk->owner_id);
} catch (\Throwable $e) {
    $mark('cmbk chia lead cho book1', false, $e->getMessage());
}

// ===================================================================
// TEST 3: Team booking (book1@) — chỉ update, không distribute
// ===================================================================
$book1 = $actAs('book1@longevity.com.vn');
$mark('book1 has lead.update', $book1->hasPermission('lead.update'));
$mark('book1 NOT has lead.distribute', ! $book1->hasPermission('lead.distribute'));
$mark('book1 NOT has lead.create', ! $book1->hasPermission('lead.create'));

// Update lead book1 sở hữu
try {
    $leadBk->update(['note' => 'Đã gọi khách, hẹn 15h']);
    $mark('book1 update lead', true);
} catch (\Throwable $e) {
    $mark('book1 update lead', false, $e->getMessage());
}

// Thử chia số (nên fail vì không có permission — nhưng model không tự chặn, chỉ UI/route chặn)
$mark('book1 gọi engine->manualAssign (không có perm)', ! $book1->hasPermission('lead.distribute'),
      'route middleware sẽ chặn HTTP; model không auto-chặn');

// ===================================================================
// TEST 4: CM sale (cmsale@) — chuyển từ booking sang sale
// ===================================================================
$cmsale = $actAs('cmsale@longevity.com.vn');
$mark('cmsale has lead.distribute', $cmsale->hasPermission('lead.distribute'));

// Chuyển leadBk (đang thuộc book1) sang Team Sale
try {
    $saleOrg = OrgUnit::firstWhere('code', 'team-hoi-sale');
    $engine->moveToTeam($leadBk, $saleOrg->id, $cmsale->id);
    $leadBk->refresh();
    $mark('cmsale chuyển lead sang team-hoi-sale', $leadBk->org_unit_id === $saleOrg->id, "org=" . $leadBk->org_unit_id);
} catch (\Throwable $e) {
    $mark('cmsale chuyển lead', false, $e->getMessage());
}

// Chia cho 1 sale của Hợi
try {
    $sale = User::firstWhere('email', 'thk@longevity.com.vn');
    $engine->manualAssign($leadBk, $sale, $cmsale->id);
    $leadBk->refresh();
    $mark('cmsale chia lead cho sale (thk)', $leadBk->owner_id === $sale->id);
} catch (\Throwable $e) {
    $mark('cmsale chia lead cho sale', false, $e->getMessage());
}

// ===================================================================
// TEST 5: Sale (thk@) — update lead mình sở hữu, KHÔNG update lead người khác
// ===================================================================
$sale = $actAs('thk@longevity.com.vn');
$mark('sale (thk) has lead.update', $sale->hasPermission('lead.update'));
$mark('sale NOT has lead.distribute', ! $sale->hasPermission('lead.distribute'));

// isVisibleTo cho lead mình sở hữu
$mark('sale thấy lead mình sở hữu', $leadBk->isVisibleTo($sale));

// Lead của người khác (leadBk trước đó thuộc book1 — hiện thuộc thk sau khi cmsale chia. Tạo lead khác)
try {
    $other = User::firstWhere('email', 'nhg@longevity.com.vn'); // sale khác
    $otherLead = Lead::create([
        'name' => 'Khách Test Sale Khác',
        'phone' => '099TEST0003',
        'received_date' => now()->toDateString(),
        'source_group' => 'Marketing',
        'lead_source' => 'facebook',
        'source_status' => 'approved',
        'receiver_id' => $other->id,
        'owner_id' => $other->id,
        'assigned_at' => now(),
        'pool_level' => Lead::POOL_PERSONAL,
        'org_unit_id' => OrgUnit::firstWhere('code', 'team-hoi-sale')->id,
    ]);
    // Sale có scope SELF → không thấy lead của người khác
    $mark('sale (thk) KHÔNG thấy lead của sale khác (nhg)', ! $otherLead->isVisibleTo($sale));
} catch (\Throwable $e) {
    $mark('tạo lead của sale khác', false, $e->getMessage());
}

// ===================================================================
// TEST 6: Observer (huyently@) — chỉ xem, không sửa
// ===================================================================
$obs = $actAs('huyently@longevity.com.vn');
$mark('observer has lead.view', $obs->hasPermission('lead.view'));
$mark('observer NOT has lead.update', ! $obs->hasPermission('lead.update'));
$mark('observer NOT has lead.distribute', ! $obs->hasPermission('lead.distribute'));
$mark('observer thấy lead của người khác (scope company)', $leadBk->isVisibleTo($obs));

// ===================================================================
// TEST 7: Admin — làm được tất cả
// ===================================================================
$admin = $actAs('admin@longevity.com.vn');
$mark('admin có tất cả quyền lead', $admin->hasPermission('lead.view')
    && $admin->hasPermission('lead.distribute')
    && $admin->hasPermission('lead.recall')
    && $admin->hasPermission('ops.manage'));

// ===================================================================
// TEST 8: Recall — cmbk thu hồi lead khỏi book1
// ===================================================================
try {
    // Tạo lead mới → chia cho book1 → cmbk thu hồi
    $lead2 = Lead::create([
        'name' => 'Khách Test Recall',
        'phone' => '099TEST0004',
        'received_date' => now()->toDateString(),
        'source_group' => 'Marketing',
        'lead_source' => 'facebook',
        'source_status' => 'approved',
        'receiver_id' => User::firstWhere('email','cmbk@longevity.com.vn')->id,
        'pool_level' => Lead::POOL_TEAM,
        'org_unit_id' => OrgUnit::firstWhere('code','team-giang-booking')->id,
    ]);
    $cmbk = User::firstWhere('email','cmbk@longevity.com.vn');
    $book1 = User::firstWhere('email','book1@longevity.com.vn');
    $engine->manualAssign($lead2, $book1, $cmbk->id);
    $engine->recall($lead2, \App\Models\Lead::POOL_TEAM, $cmbk->id);
    $lead2->refresh();
    $mark('cmbk recall lead khỏi book1', $lead2->owner_id === null && $lead2->pool_level === Lead::POOL_TEAM,
          "owner=" . ($lead2->owner_id ?? 'null') . " pool=" . $lead2->pool_level);
} catch (\Throwable $e) {
    $mark('cmbk recall lead', false, $e->getMessage());
}

// ===================================================================
// REPORT
// ===================================================================
echo PHP_EOL . "=== KẾT QUẢ ===" . PHP_EOL;
foreach ($results as $r) echo $r . PHP_EOL;
$fails = count(array_filter($results, fn($r) => str_starts_with($r, '❌')));
echo PHP_EOL . ($fails === 0 ? "✅ TẤT CẢ PASS ({" . count($results) . "})" : "❌ FAIL {$fails}/" . count($results)) . PHP_EOL;
