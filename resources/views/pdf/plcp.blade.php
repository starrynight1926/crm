<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>PLCP - {{ $ma_kh }}</title>
<style>
  @page { margin: 10mm 14mm 14mm 20mm; }
  @page p02 { margin: 0; background-image: url('{{ public_path('downloads/plcp-pages/page-02.png') }}'); background-image-resize: 6; background-image-opacity: 1; }
  @page p03 { margin: 0; background-image: url('{{ public_path('downloads/plcp-pages/page-03.png') }}'); background-image-resize: 6; background-image-opacity: 1; }
  @page p04 { margin: 0; background-image: url('{{ public_path('downloads/plcp-pages/page-04.png') }}'); background-image-resize: 6; background-image-opacity: 1; }
  .pg02 { page: p02; page-break-before: always; }
  .pg03 { page: p03; page-break-before: always; }
  .pg04 { page: p04; page-break-before: always; }
  body { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #111; line-height: 1.4; }

  /* ===== Decor (viền trái nâu + footer) absolute theo page ===== */
  .brown-strip {
    position: absolute;
    left: 0; top: 0;
    width: 5mm; height: 297mm;
    background: #a0763a;
  }
  .pg-footer {
    position: absolute;
    right: 14mm; bottom: 9mm;
    font-size: 9pt; font-weight: bold; color: #484848;
    letter-spacing: 0.3px;
  }

  /* ===== Logo + Clinic header (logo trái, địa chỉ phải) ===== */
  table.header { width: 100%; border-collapse: collapse; margin: 0 0 5mm 0; }
  table.header td { vertical-align: middle; padding: 0; }
  table.header td.logo { padding-right: 4mm; }
  table.header td.clinic { text-align: left; padding-left: 50mm; font-size: 9pt; color: #333; line-height: 1.5; white-space: nowrap; }
  table.header td.clinic .clinic-name { font-weight: bold; font-size: 10pt; color: #111; }

  /* ===== Title ===== */
  .title-lg { font-size: 15pt; font-weight: bold; color: #5c4d46; margin: 0; letter-spacing: 0.3px; }
  .title-sm { font-size: 12.5pt; font-weight: bold; color: #5c4d46; margin: 1mm 0 5mm 0; letter-spacing: 0.3px; }

  /* ===== Info grid ===== */
  table.grid { width: 100%; border-collapse: collapse; margin-bottom: 2.5mm; }
  table.grid td { vertical-align: bottom; padding: 0; }
  .lbl { font-weight: normal; white-space: nowrap; padding-right: 2mm !important; }
  .val {
    font-weight: bold;
    border-bottom: 1px dotted #333;
    padding: 0 1mm 1mm 1mm !important;
    height: 5mm;
  }
  .val.empty { font-weight: normal; }

  /* ===== Section header ===== */
  .section-title { font-size: 10pt; font-weight: bold; margin: 4mm 0 2mm 0; }
  .section-title.part { font-size: 11.5pt; color: #5c4d46; margin-top: 5mm; text-transform: uppercase; }

  /* ===== Checkboxes ===== */
  table.checks { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
  table.checks td { padding: 1.2mm 0; font-size: 9.5pt; }
  .cb {
    font-size: 13pt;
    margin-right: 1.5mm;
    vertical-align: middle;
    color: #333;
  }
</style>
</head>
<body>

{{-- ========== Decor trang 1 ========== --}}
<div class="brown-strip"></div>
<div class="pg-footer">HỒ SƠ CHĂM SÓC SỨC KHỎE CÁ NHÂN HÓA - 1</div>

{{-- ========== HEADER: logo trái, địa chỉ phòng khám phải ========== --}}
<table class="header" width="100%">
  <tr>
    <td class="logo" width="60mm"><img src="{{ public_path('images/logo-plcp.png') }}" style="width: 54mm;" alt="Longevity Medical Center"></td>
    <td class="clinic">
      <div class="clinic-name">Phòng khám Đa khoa Longevity Medical Center</div>
      <div>Địa chỉ: 57-59 Ngô Thì Nhậm, P. Hai Bà Trưng, TP. Hà Nội</div>
      <div>Điện thoại: 0559188888 &nbsp;&nbsp;-&nbsp; Website: Longevity.com.vn</div>
    </td>
  </tr>
</table>

{{-- ========== TITLE ========== --}}
<p class="title-lg">PERSONALIZED LONGEVITY CARE PROFILE (PLCP)</p>
<p class="title-sm">HỒ SƠ CHĂM SÓC SỨC KHỎE CÁ NHÂN HÓA</p>

{{-- ========== INFO (Mã KH / Ngày / Cơ sở / PHA / Bác sĩ) ========== --}}
<table class="grid">
  <tr>
    <td class="lbl" width="30mm">Mã khách hàng:</td>
    <td class="val" width="70mm">{{ $ma_kh }}</td>
    <td width="6mm"></td>
    <td class="lbl" width="30mm">Ngày lập hồ sơ:</td>
    <td class="val">{{ $ngay_lap }}</td>
  </tr>
</table>

<table class="grid"><tr><td class="lbl" width="14mm">Cơ sở:</td><td class="val">{{ $co_so }}</td></tr></table>

<table class="grid">
  <tr>
    <td class="lbl" width="60mm">Personalized Health Advisor (PHA):</td>
    <td class="val empty" width="18mm">&nbsp;</td>
    <td width="6mm"></td>
    <td class="lbl" width="32mm">Bác sĩ phụ trách:</td>
    <td class="val" style="white-space: nowrap;">{{ $bac_si }}</td>
  </tr>
</table>

{{-- Nguồn khách hàng --}}
<p class="section-title">NGUỒN KHÁCH HÀNG:</p>
<table class="checks">
  <tr>
    <td width="25%"><span class="cb">☐</span>Giới thiệu</td>
    <td width="25%"><span class="cb">☐</span>Khách hàng hiện hữu</td>
    <td width="25%"><span class="cb">☐</span>Đối tác</td>
    <td width="25%"><span class="cb">☐</span>Sự kiện</td>
  </tr>
  <tr>
    <td><span class="cb">☐</span>Facebook</td>
    <td><span class="cb">☐</span>Website</td>
    <td><span class="cb">☐</span>Cuộc hẹn</td>
    <td><span class="cb">☐</span>Khác</td>
  </tr>
</table>

{{-- ========== PHẦN 1 ========== --}}
<p class="section-title part">PHẦN 1: THÔNG TIN CÁ NHÂN / PERSONAL INFORMATION</p>

<table class="grid">
  <tr>
    <td class="lbl" width="22mm">Họ và tên:</td>
    <td class="val" width="78mm">{{ $ho_ten }}</td>
    <td width="6mm"></td>
    <td class="lbl" width="22mm">Ngày sinh:</td>
    <td class="val empty">&nbsp;</td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="18mm">Giới tính:</td>
    <td width="82mm">
      <span class="cb">☐</span>Nam &nbsp;&nbsp;
      <span class="cb">☐</span>Nữ &nbsp;&nbsp;
      <span class="cb">☐</span>Khác
    </td>
    <td width="6mm"></td>
    <td class="lbl" width="28mm">Số điện thoại:</td>
    <td class="val empty">&nbsp;</td>
  </tr>
</table>

<table class="grid"><tr><td class="lbl" width="14mm">Email:</td><td class="val empty">&nbsp;</td></tr></table>
<table class="grid"><tr><td class="lbl" width="14mm">Địa chỉ:</td><td class="val empty">&nbsp;</td></tr></table>
<table class="grid"><tr><td class="lbl" width="22mm">Nghề nghiệp:</td><td class="val empty">&nbsp;</td></tr></table>

<table class="grid">
  <tr>
    <td class="lbl" width="38mm">Tình trạng hôn nhân:</td>
    <td>
      <span class="cb">☐</span>Độc thân &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Kết hôn &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Khác
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="58mm">Có dự định sinh con sắp tới không:</td>
    <td>
      <span class="cb">☐</span>Có &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Không
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="42mm">Người liên hệ khẩn cấp:</td>
    <td class="val empty" width="60mm">&nbsp;</td>
    <td width="6mm"></td>
    <td class="lbl" width="28mm">Số điện thoại:</td>
    <td class="val empty">&nbsp;</td>
  </tr>
</table>

{{-- ========== PHẦN 2 (nửa đầu — nửa sau nằm ở trang 2 PNG) ========== --}}
<p class="section-title part">PHẦN 2: THÔNG TIN SỨC KHỎE &amp; LỐI SỐNG</p>

<table class="grid">
  <tr>
    <td class="lbl" width="30mm">Chỉ số cơ bản:</td>
    <td>
      <table style="border-collapse:collapse;width:100%;"><tr>
        <td style="white-space:nowrap;padding-right:1mm;">Chiều cao:</td>
        <td style="border-bottom:1px dotted #333;width:18mm;">&nbsp;</td>
        <td style="white-space:nowrap;padding:0 1mm 0 3mm;">Cân nặng:</td>
        <td style="border-bottom:1px dotted #333;width:18mm;">&nbsp;</td>
        <td style="white-space:nowrap;padding:0 1mm 0 3mm;">BMI:</td>
        <td style="border-bottom:1px dotted #333;width:18mm;">&nbsp;</td>
        <td style="white-space:nowrap;padding:0 1mm 0 3mm;">Huyết áp:</td>
        <td style="border-bottom:1px dotted #333;width:18mm;">&nbsp;</td>
        <td style="white-space:nowrap;padding:0 1mm 0 3mm;">Mạch:</td>
        <td style="border-bottom:1px dotted #333;width:18mm;">&nbsp;</td>
      </tr></table>
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="30mm">Hút thuốc:</td>
    <td>
      <span class="cb">☐</span>Không &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Đã từng &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Hiện tại
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="30mm">Rượu bia:</td>
    <td>
      <span class="cb">☐</span>Không &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Thỉnh thoảng &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Hàng tuần &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Thường xuyên
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="30mm">Mức độ căng thẳng:</td>
    <td>
      <span class="cb">☐</span>Thấp &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Trung bình &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Cao &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Rất cao
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="30mm">Mức độ bận rộn:</td>
    <td>
      <span class="cb">☐</span>Thấp &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Trung bình &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Cao &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>Rất cao
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="40mm">Thời gian ngồi mỗi ngày:</td>
    <td>
      <span class="cb">☐</span>&lt;4 giờ &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>4-8 giờ &nbsp;&nbsp;&nbsp;
      <span class="cb">☐</span>&gt;8 giờ
    </td>
  </tr>
</table>

<table class="grid"><tr><td class="lbl" width="35mm">Chất lượng giấc ngủ:</td><td class="val empty">&nbsp;</td></tr></table>
<table class="grid"><tr><td class="lbl" width="40mm">Tần suất khám sức khỏe:</td><td class="val empty">&nbsp;</td></tr></table>
<table class="grid"><tr><td class="lbl" width="35mm">Nơi khám sức khỏe:</td><td class="val empty">&nbsp;</td></tr></table>

{{-- ==================== TRANG 2-4: PNG làm background @page để full-bleed A4 ==================== --}}
<div class="pg02">&nbsp;</div>
<div class="pg03">&nbsp;</div>
<div class="pg04">&nbsp;</div>

</body>
</html>
