<?php
/** QA 21 cases: 7 nguồn × 3 cơ sở (fallback dùng model+service, không mount Livewire). */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{User, Lead, DailyAttendance, PoolUnit, OrgUnit, UpsDailyConfirm};
use Illuminate\Support\Facades\{Auth, DB};

// --- Seed UPS check-in cho 3 facility (cần cho MKT/WI/HL round-robin) ---
$facMap = [
    'HN'  => PoolUnit::where('code','pool-cs-hn-1')->first(),
    'DN'  => PoolUnit::where('code','pool-cs-dn-1')->first(),
    'HCM' => PoolUnit::where('code','pool-cs-hcm-1')->first(),
];

// Pick 3 sale per branch (or fallback query)
$salesByBranch = [
    'HN'  => User::where('email','like','hn.sale%')->limit(3)->pluck('id','email')->toArray(),
    'DN'  => User::where('email','like','dn.sale%')->limit(3)->pluck('id','email')->toArray(),
    'HCM' => User::where('email','like','hcm.sale%')->limit(3)->pluck('id','email')->toArray(),
];

echo "== Seed UPS check-in ==\n";
foreach ($facMap as $br => $fac) {
    $today = today();
    $i = 0;
    foreach ($salesByBranch[$br] as $email => $uid) {
        // Sale đầu tiên = bucket MKT (up SA), 2 sale sau = bucket A (up BA + tiếp đón)
        $isMkt = ($i === 0);
        DailyAttendance::updateOrCreate(
            ['user_id'=>$uid, 'work_date'=>$today],
            ['facility_pool_unit_id'=>$fac->id, 'checkin_at'=>now()->setTime(8,0),
             'list_bucket'=>$isMkt?'MKT':'A', 'is_mkt'=>$isMkt,
             'is_off'=>false, 'is_busy'=>false, 'dung_nhan_lead'=>false]
        );
        $i++;
    }
    UpsDailyConfirm::updateOrCreate(
        ['facility_pool_unit_id'=>$fac->id, 'work_date'=>$today],
        ['confirmed_by'=>1, 'confirmed_at'=>now()]
    );
    echo "  $br fac_id={$fac->id}: ".count($salesByBranch[$br])." sale check-in + UPS confirmed\n";
}

// --- Users cho từng vai trò per branch ---
$actorMap = [
    'MKT'    => ['HN'=>'hn.page01@longevity.com.vn',  'DN'=>'dn.page01@longevity.com.vn',  'HCM'=>'hcm.page01@longevity.com.vn'],
    'WI'     => ['HN'=>'admin.hn@longevity.com.vn',   'DN'=>'admin.dn@longevity.com.vn',   'HCM'=>'admin.hcm@longevity.com.vn'],
    'BDM'    => ['HN'=>'hn.cms01@longevity.com.vn',   'DN'=>'dn.cms01@longevity.com.vn',   'HCM'=>'hcm.cms01@longevity.com.vn'],
    'BOD'    => ['HN'=>'hn.cms01@longevity.com.vn',   'DN'=>'dn.cms01@longevity.com.vn',   'HCM'=>'hcm.cms01@longevity.com.vn'],
    // SA cần sale check-in bucket MKT (rule 2026-08-09) — dùng sale đầu (isMkt=true).
    'SA'     => ['HN'=>'hn.sale03@longevity.com.vn',  'DN'=>'dn.sale01@longevity.com.vn',  'HCM'=>'hcm.sale01@longevity.com.vn'],
    'MKT_BR' => ['HN'=>'hn.sale04@longevity.com.vn',  'DN'=>'dn.sale02@longevity.com.vn',  'HCM'=>'hcm.sale02@longevity.com.vn'],
    // HL: sale trực hotline → self-owned. Dùng sale bucket A (sale thứ 2) để không đụng SA.
    'HL'     => ['HN'=>'hn.sale05@longevity.com.vn',  'DN'=>'dn.sale03@longevity.com.vn',  'HCM'=>'hcm.sale03@longevity.com.vn'],
];
$sourceMap = ['MKT'=>Lead::SOURCE_MKT,'WI'=>Lead::SOURCE_WI,'BDM'=>Lead::SOURCE_BDM,
              'BOD'=>Lead::SOURCE_BOD,'SA'=>Lead::SOURCE_SA,'MKT_BR'=>Lead::SOURCE_MKT_BR,
              'HL'=>Lead::SOURCE_HL];

$results = [];
$phoneSeq = 900000000;
foreach (['MKT','WI','BDM','BOD','SA','MKT_BR','HL'] as $sg) {
    foreach (['HN','DN','HCM'] as $br) {
        $case = "$sg / $br";
        try {
            $email = $actorMap[$sg][$br];
            $u = User::where('email', $email)->first();
            if (! $u) { $results[] = [$case,'FAIL',"user $email not found"]; continue; }
            Auth::login($u);

            $phone = '0'.(++$phoneSeq);
            $lw = \Livewire\Livewire::actingAs($u)->test('leads.⚡lead-form');
            $lw->set('name', "QA-{$sg}-{$br}-".uniqid())
               ->set('phone', $phone)
               ->set('received_date', today()->toDateString())
               ->set('bookingStatus', Lead::BOOKING_NOT_BOOKED)
               ->set('sourceGroup', $sourceMap[$sg]);

            // BOD (CM-assigned): CM phải chọn personId (sale trong team). Pick sale đầu của branch.
            if ($sg === 'BOD') {
                $targetSale = array_values($salesByBranch[$br])[0] ?? null;
                if ($targetSale) $lw->set('personId', $targetSale);
            }

            $lw->call('save');

            $lead = Lead::where('phone', str_replace(['0'],['84'],$phone))
                        ->orWhere('phone',$phone)->latest('id')->first();
            if (! $lead) { $results[] = [$case,'FAIL','lead không tạo được: '.json_encode($lw->errors()->all())]; continue; }

            // Assert per source classification
            $expect = '';
            if (in_array($sg, ['MKT','WI'])) {
                $expect = 'UPS→owner set';
                $ok = $lead->owner_id !== null;
            } elseif ($sg === 'BDM') {
                $expect = 'CM-assigned no auto-owner';
                $ok = $lead->owner_id === null && in_array($lead->pool_level, [Lead::POOL_TEAM, Lead::POOL_COMMON]);
            } elseif ($sg === 'BOD') {
                // CM chọn personId = valid CM-assigned outcome → owner=personId.
                $targetSale = array_values($salesByBranch[$br])[0] ?? null;
                $expect = "CM chỉ định owner={$targetSale}";
                $ok = $lead->owner_id === $targetSale && $lead->pool_level === Lead::POOL_PERSONAL;
            } elseif (in_array($sg, ['SA','MKT_BR','BA','HL'])) {
                $expect = "self-owned owner={$u->id}";
                $ok = $lead->owner_id === $u->id;
            } else $ok = false;

            $extra = "lead#{$lead->id} owner={$lead->owner_id} pool={$lead->pool_level}";
            $results[] = [$case, $ok?'PASS':'FAIL', "$expect | $extra"];
        } catch (\Throwable $e) {
            $results[] = [$case,'ERR', substr($e->getMessage(),0,120)];
        } finally {
            Auth::logout();
        }
    }
}

echo "\n== KẾT QUẢ ==\n";
foreach ($results as [$c,$st,$msg]) printf("  [%s] %-15s %s\n", $st, $c, $msg);
$pass = count(array_filter($results, fn($r)=>$r[1]==='PASS'));
echo "\nTổng: $pass/".count($results)." PASS\n";
