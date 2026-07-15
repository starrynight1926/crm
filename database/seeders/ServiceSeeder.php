<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        // Xóa data cũ
        Service::query()->delete();

        // ═══════════════════════════════════════════════════════════
        // 1. BẢNG GIÁ NIÊM YẾT — Gói khám tầm soát
        // ═══════════════════════════════════════════════════════════
        $tamSoat = $this->cat('CAT-TS', 'Gói khám tầm soát');

        // — PK Đa khoa Hà Nội
        $hn = $this->cat('CAT-TS-HN', 'Phòng khám đa khoa (Hà Nội)', $tamSoat);
        $this->svc('SIG-NAM',  'Gói khám chuyên sâu Signature Nam',  $hn, 7019000, 300);
        $this->svc('SIG-NU',   'Gói khám chuyên sâu Signature Nữ',   $hn, 7429000, 300);
        $this->svc('DIA-NAM',  'Gói khám định kỳ Diamond Nam',       $hn, 3463000, 140);
        $this->svc('DIA-NU',   'Gói khám định kỳ Diamond Nữ',        $hn, 3713000, 140);
        $this->svc('EHC-NAM',  'Gói khám Executive Health Check Nam (DN)', $hn, 3683000, 140);
        $this->svc('EHC-NU',   'Gói khám Executive Health Check Nữ (DN)',  $hn, 3933000, 140);
        $this->svc('TQ',       'Gói khám sức khỏe tổng quát',        $hn, 1728000, 70);
        $this->svc('CXK',      'Gói chuyên sâu Cơ xương khớp',       $hn, 2013000, 80);
        $this->svc('TIM',      'Gói chuyên sâu Tim mạch & đột quỵ',  $hn, 2852000);
        $this->svc('GAN',      'Gói chuyên sâu Gan',                  $hn, 3008000);
        $this->svc('TD',       'Gói chuyên sâu Tiểu đường',           $hn, 1339000);
        $this->svc('TG',       'Gói chuyên sâu Tuyến giáp',           $hn, 1205000);
        $this->svc('RLCH',     'Gói chuyên sâu Rối loạn chuyển hóa',  $hn, 2904000);

        // — PK Chuyên khoa HCM
        $hcm = $this->cat('CAT-TS-HCM', 'Phòng khám chuyên khoa (HCM)', $tamSoat);
        $this->svc('VVIP-NU',  'Gói khám VVIP Nữ',                    $hcm, 5200000, 200);
        $this->svc('VVIP-NAM', 'Gói khám VVIP Nam',                   $hcm, 4600000, 180);
        $this->svc('XNSA',     'Gói xét nghiệm và siêu âm tổng quát', $hcm, 1600000, 70);

        // ═══════════════════════════════════════════════════════════
        // 2. GENE
        // ═══════════════════════════════════════════════════════════
        $gene = $this->cat('CAT-GENE', 'Gene');
        $this->svc('G2-PLUS',  'Gene2 me Plus',                 $gene, null, 1500);
        $this->svc('G2',       'Gene2 me',                      $gene, null, 1000);
        $this->svc('TRUAGE',   'TruAge',                         $gene, null, 2000);
        $this->svc('G2-COMBO', 'Gene2 + Gene2 Plus + TruAge',   $gene, null, 3000);
        $this->svc('TRUAGE-R', 'Return TruAge',                  $gene, null, 2000);

        // ═══════════════════════════════════════════════════════════
        // 3. DỊCH VỤ LẺ — Khớp, Thủy châm, YHPĐ
        // ═══════════════════════════════════════════════════════════
        $dvLe = $this->cat('CAT-DVLE', 'Dịch vụ lẻ');

        $this->svc('TC',       'Thủy châm (1 vùng)',             $dvLe, null, 1300);
        $this->svc('BJR',      'BJR (1 vùng)',                   $dvLe, null, 1300);
        $this->svc('HA1',      'HA 1%/khớp',                     $dvLe, 3000000, 120);
        $this->svc('HA2',      'HA 2%/khớp',                     $dvLe, 10000000, 380);
        $this->svc('PRP',      'PRP/khớp',                       $dvLe, 4500000, 180);
        $this->svc('CORT',     'Corticoid (1 khớp)',              $dvLe, null, 20);
        $this->svc('YHPD',     'Y học Phương Đông',               $dvLe, 1000000, 40);
        $this->svc('DOX-XONG', 'DeepOxy & DetoxCell (xông)',      $dvLe, 700000, 30);
        $this->svc('DOX-TH',   'DeepOxy & DetoxCell (tổng hợp)', $dvLe, 1500000, 60);
        $this->svc('DETOXOXY', 'DetoxOxy (xông thủy/hỏa)',       $dvLe, null, 30);
        $this->svc('TC-CVG',   'Thủy châm cổ vai gáy EAQ',      $dvLe, null, 1300);
        $this->svc('CSDC',     'Chăm sóc da cơ bản',             $dvLe, null, 100);

        // ═══════════════════════════════════════════════════════════
        // 4. TẾ BÀO GỐC
        // ═══════════════════════════════════════════════════════════
        $tbg = $this->cat('CAT-TBG', 'Tế bào gốc');
        $this->svc('STC-JP',   'STC Japan',                      $tbg, null, 20000);
        $this->svc('NK',       'NK',                              $tbg, null, 6000);
        $this->svc('STC-SG',   'STC Singapore Recells 450 Mil',  $tbg, null, 15000);
        $this->svc('XSOME',    'Xsome 2ml',                      $tbg, null, 1300);
        $this->svc('TSUT',     'Tầm soát UT Gen',                $tbg, null, 3000);
        $this->svc('RETREAT',  'Retreat',                         $tbg, null, 2000);
        $this->svc('MB300',    'Metaboost 300',                   $tbg, null, 950, 'Giá lẻ tính theo đơn vị $950/lần');
        $this->svc('NMN',      'NMN Cap',                        $tbg, null, 350, 'Loại 660 cho quà tặng KH cũ');

        // ═══════════════════════════════════════════════════════════
        // 5. MEMO GIẢI PHÁP VỀ KHỚP
        // ═══════════════════════════════════════════════════════════
        $khop = $this->cat('CAT-KHOP', 'Memo Giải pháp Khớp');

        $this->svc('PHMO-1', 'Gói Phục hồi mô tổn thương CĐ1', $khop, null, 400,
            "PRP (2 khớp) x1: $360 | YHPĐ x5: $200 | Tặng: Gói khám Diamond $140, chuyên đề CXK, CSSK 24/7");
        $this->svc('PHMO-2', 'Gói Phục hồi mô tổn thương CĐ2', $khop, null, 700,
            "PRP (2 khớp) x2: $720 | YHPĐ x10: $400 | Tặng: Gói khám Diamond $140, chuyên đề CXK, CSSK 24/7");
        $this->svc('PHMO-3', 'Gói Phục hồi mô tổn thương CĐ3', $khop, null, 1200,
            "PRP (2 khớp) x4: $1,440 | YHPĐ x20: $800 | Tặng: Gói khám Diamond $140, chuyên đề CXK, CSSK 24/7");

        $this->svc('BJR-3M', 'Gói Bổ sung dịch nhầy Khớp 3 tháng', $khop, null, 3900,
            "BJR x3: $3,900 | Tặng: NMN Cap x2: $700, HA1% x2: $240, Gói khám Diamond $140");
        $this->svc('BJR-6M', 'Gói Bổ sung dịch nhầy Khớp 6 tháng', $khop, null, 6900,
            "BJR x6: $7,800 | Tặng: NMN Cap x4: $1,400, HA1% x2: $240, Gói khám Diamond $140");
        $this->svc('BJR-12M', 'Gói Bổ sung dịch nhầy Khớp 12 tháng', $khop, null, 12900,
            "BJR x12: $15,600 | Tặng: NMN Cap x8: $2,800, HA1% x4: $480, Gói khám Diamond x2: $280");

        // ═══════════════════════════════════════════════════════════
        // 6. MEMO GIẢI PHÁP CỔ VAI GÁY
        // ═══════════════════════════════════════════════════════════
        $cvg = $this->cat('CAT-CVG', 'Memo Giải pháp Cổ vai gáy');

        $this->svc('TC-3M', 'Gói Thủy châm tái tạo mô 3 tháng', $cvg, null, 3900,
            "EAQ x3: $3,900 | Tặng: DeepOxy xông x20: $600, YHPĐ x20: $800, NMN Cap x2: $700, Gói khám Diamond x2: $280");
        $this->svc('TC-6M', 'Gói Thủy châm tái tạo mô 6 tháng', $cvg, null, 6900,
            "EAQ x6: $7,800 | Tặng: DeepOxy xông x40: $1,200, YHPĐ x40: $1,600, NMN Cap x4: $1,400, Gói khám Diamond x2: $280");
        $this->svc('TC-12M', 'Gói Thủy châm tái tạo mô 1 năm', $cvg, null, 12900,
            "EAQ x12: $15,600 | Tặng: DeepOxy xông x80: $2,400, YHPĐ x80: $3,200, NMN Cap x8: $2,800, Gói khám Diamond x3: $420");

        $this->svc('DOX-10', 'Gói DeepOxy & DetoxCell 10 lần', $cvg, null, 600,
            "DeepOxy tổng hợp x10: $600 | Tặng: DeepOxy tổng hợp x2: $120, Gói khám Diamond $140 | CK: mua 2 gói trở lên -5%");
        $this->svc('DOX-20', 'Gói DeepOxy & DetoxCell 20 lần', $cvg, null, 1200,
            "DeepOxy tổng hợp x20: $1,200 | Tặng: NMN Cap x1: $350, Gói khám Diamond $140 | CK: mua 2 gói trở lên -10%");

        // ═══════════════════════════════════════════════════════════
        // 7. LONGEVITY LIFECARE (STC Singapore combos)
        // ═══════════════════════════════════════════════════════════
        $llc = $this->cat('CAT-LLC', 'Longevity Lifecare');

        $this->svc('STC-1X',  'STC Singapore 1 lần',   $llc, null, 15000);
        $this->svc('STC-2X',  'STC Singapore 2 lần',   $llc, null, 27000);
        $this->svc('STC-4X',  'STC Singapore 4 lần',   $llc, null, 48000);

        $this->svc('OC-3M', 'OneCare Longevity 3 Month', $llc, null, 15000,
            "STC SG x1: $15,000 | Tặng: Metaboost x6: $5,700, Xsome x6: $7,800, NMN Cap x2: $700, Gói khám Signature $300");
        $this->svc('OC-6M', 'OneCare Longevity 6 Month', $llc, null, 27000,
            "STC SG x2: $30,000 | Tặng: Metaboost x12: $11,400, Xsome x12: $15,600, NMN Cap x4: $1,400, Gói khám OneCare365 $1,000");
        $this->svc('OC-12M', 'OneCare Longevity 12 Month', $llc, null, 48000,
            "STC SG x4: $60,000 | Tặng: Metaboost x24: $22,800, Xsome x24: $31,200, NMN Cap x8: $2,800, Gói khám OneCare365 $1,000");

        // ═══════════════════════════════════════════════════════════
        // 8. STEM CELL JAPAN
        // ═══════════════════════════════════════════════════════════
        $scj = $this->cat('CAT-SCJ', 'Stem Cell Japan');

        $this->svc('SCJ-1', 'Stem Cell Japan 1 lần', $scj, null, 20000);
        $this->svc('SCJ-2', 'Stem Cell Japan 2 lần', $scj, null, 36000);
        $this->svc('SCJ-4', 'Stem Cell Japan 4 lần', $scj, null, 66000,
            "Tặng (<4 lần): $5,010 gồm Phí khám, XN VVIP, Diamond x4, Visa, VIP, phiên dịch, DeepOxy x5, YHPĐ x5, Metaboost x3, NMN x2");
        $this->svc('SCJ-6', 'Stem Cell Japan 6 lần', $scj, null, 90000,
            "Tặng (≥4 lần): $8,140 gồm Phí khám, XN VVIP, Diamond x6, Visa, VIP, phiên dịch, DeepOxy x5, YHPĐ x5, Metaboost x6, NMN x2");

        // ═══════════════════════════════════════════════════════════
        // 9. GÓI KHÁM SỨC KHỎE (Chiết khấu)
        // ═══════════════════════════════════════════════════════════
        $gksk = $this->cat('CAT-GKSK', 'Gói khám sức khỏe (Chiết khấu)');

        $this->svc('CK-SIG-NAM', 'CK Signature Nam',   $gksk, 4913300, 300);
        $this->svc('CK-SIG-NU',  'CK Signature Nữ',    $gksk, 5200300, 300);
        $this->svc('CK-VVIP-NAM','CK VVIP Nam (HCM)',  $gksk, 3640000, 180);
        $this->svc('CK-VVIP-NU', 'CK VVIP Nữ (HCM)',  $gksk, 3220000, 200);

        // ═══════════════════════════════════════════════════════════
        // 10. ONECARE 365
        // ═══════════════════════════════════════════════════════════
        $oc365 = $this->cat('CAT-OC365', 'OneCare 365');

        $this->svc('OC365', 'OneCare 365', $oc365, null, 1000,
            "Gói khám Signature x2: $600 | Quản trị đau (DeepOxy/YHPĐ) x15: $900 HOẶC CSDC x15: $1,500 HOẶC NMN Cap x2: $700 | Tặng: thăm khám không giới hạn, hồ sơ SKCH | Tối đa 3 gói/KH");
        $this->svc('OC365-SIG', 'Signature OneCare 365', $oc365, null, 2200,
            "Gói khám Signature x2: $600 | Quản trị đau x15: $900 HOẶC CSDC x15: $1,500 HOẶC NMN Cap x2: $700 | Tặng: thăm khám không giới hạn, hồ sơ SKCH, Metaboost x6: $5,700 | Tối đa 3 gói/KH");
    }

    private function cat(string $code, string $name, ?Service $parent = null): Service
    {
        return Service::updateOrCreate(['code' => $code], [
            'name' => $name,
            'parent_id' => $parent?->id,
            'pricing_type' => 'package',
            'active' => true,
        ]);
    }

    private function svc(
        string $code, string $name, Service $parent,
        ?int $vnd = null, ?int $usd = null, ?string $notes = null
    ): Service {
        return Service::updateOrCreate(['code' => $code], [
            'name' => $name,
            'parent_id' => $parent->id,
            'pricing_type' => 'package',
            'package_price' => $vnd,
            'price_usd' => $usd,
            'active' => true,
            'notes' => $notes,
        ]);
    }
}
