<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>PLCP - {{ $ma_kh }}</title>
<style>
  @page { margin: 10mm 14mm 12mm 14mm; }
  body { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #111; line-height: 1.35; }

  /* ===== Header ===== */
  table.header { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
  table.header td { vertical-align: top; padding: 0; }
  .brand-logo {
    width: 15mm; height: 15mm;
    background: #c7ad51;
    color: #fff;
    font-family: Georgia, serif;
    font-size: 13pt;
    font-weight: bold;
    text-align: center;
    line-height: 15mm;
    border-radius: 2mm;
  }
  .brand-text {
    font-family: Georgia, serif;
    font-size: 15pt;
    font-weight: bold;
    color: #786b58;
    letter-spacing: 1px;
    line-height: 1;
  }
  .brand-sub {
    font-family: dejavusans, sans-serif;
    font-size: 6.5pt;
    color: #786b58;
    letter-spacing: 1px;
    margin-top: 1mm;
  }
  .clinic {
    font-family: Georgia, serif;
    font-size: 9.5pt;
    line-height: 1.55;
    color: #222;
  }
  .clinic b { display: block; margin-bottom: 1mm; }

  /* ===== Title ===== */
  .title-lg { font-size: 15pt; font-weight: bold; color: #5c4d46; margin: 0; letter-spacing: 0.3px; }
  .title-sm { font-size: 12pt; font-weight: bold; color: #5c4d46; margin: 1mm 0 5mm 0; letter-spacing: 0.3px; }

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

  /* ===== Section ===== */
  .section-title { font-size: 10pt; font-weight: bold; margin: 4mm 0 2mm 0; }
  .section-title.part { font-size: 11pt; color: #5c4d46; margin-top: 5mm; text-transform: uppercase; }

  /* ===== Checkboxes ===== */
  table.checks { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
  table.checks td { padding: 1.2mm 0; font-size: 9.5pt; }
  .cb {
    display: inline-block;
    width: 3mm; height: 3mm;
    border: 0.3mm solid #333;
    margin-right: 1.5mm;
    vertical-align: middle;
  }
</style>
</head>
<body>

{{-- ==================== HEADER (logo + clinic) ==================== --}}
<table class="header">
  <tr>
    <td width="45%">
      <table style="border-collapse:collapse"><tr>
        <td width="17mm"><div class="brand-logo">LMC</div></td>
        <td style="padding-left:2mm;vertical-align:middle">
          <div class="brand-text">LONGEVITY</div>
          <div class="brand-sub">MEDICAL SYSTEM</div>
        </td>
      </tr></table>
    </td>
    <td width="55%" class="clinic">
      <b>Phòng khám Đa khoa Longevity Medical Center</b>
      Địa chỉ: 57-59 Ngô Thì Nhậm. P. Hai Bà Trưng, TP. Hà Nội<br>
      Điện thoại: 0559188888 &nbsp;-&nbsp; Website: Longevity.com.vn
    </td>
  </tr>
</table>

{{-- ==================== TITLE ==================== --}}
<p class="title-lg">PERSONALIZED LONGEVITY CARE PROFILE (PLCP)</p>
<p class="title-sm">HỒ SƠ CHĂM SÓC SỨC KHỎE CÁ NHÂN HÓA</p>

{{-- ==================== INFO (2 cột) ==================== --}}
<table class="grid">
  <tr>
    <td class="lbl" width="30mm">Mã khách hàng:</td>
    <td class="val" width="70mm">{{ $ma_kh }}</td>
    <td width="6mm"></td>
    <td class="lbl" width="30mm">Ngày lập hồ sơ:</td>
    <td class="val">{{ $ngay_lap }}</td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="14mm">Cơ sở:</td>
    <td class="val">{{ $co_so }}</td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="60mm">Personalized Health Advisor (PHA):</td>
    <td class="val empty" width="40mm">&nbsp;</td>
    <td width="6mm"></td>
    <td class="lbl" width="32mm">Bác sĩ phụ trách:</td>
    <td class="val">{{ $bac_si }}</td>
  </tr>
</table>

{{-- Nguồn khách hàng --}}
<p class="section-title">NGUỒN KHÁCH HÀNG:</p>
<table class="checks">
  <tr>
    <td width="25%"><span class="cb"></span>Giới thiệu</td>
    <td width="25%"><span class="cb"></span>Khách hàng hiện hữu</td>
    <td width="25%"><span class="cb"></span>Đối tác</td>
    <td width="25%"><span class="cb"></span>Sự kiện</td>
  </tr>
  <tr>
    <td><span class="cb"></span>Facebook</td>
    <td><span class="cb"></span>Website</td>
    <td><span class="cb"></span>Cuộc hẹn</td>
    <td><span class="cb"></span>Khác</td>
  </tr>
</table>

{{-- ==================== PHẦN 1 ==================== --}}
<p class="section-title part">PHẦN 1: THÔNG TIN CÁ NHÂN / PERSONAL INFORMATION</p>

<table class="grid">
  <tr>
    <td class="lbl" width="22mm">Họ và tên:</td>
    <td class="val" width="88mm">{{ $ho_ten }}</td>
    <td width="6mm"></td>
    <td class="lbl" width="22mm">Ngày sinh:</td>
    <td class="val empty">&nbsp;</td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="18mm">Giới tính:</td>
    <td width="92mm">
      <span class="cb"></span>Nam &nbsp;&nbsp;
      <span class="cb"></span>Nữ &nbsp;&nbsp;
      <span class="cb"></span>Khác
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
      <span class="cb"></span>Độc thân &nbsp;&nbsp;
      <span class="cb"></span>Kết hôn &nbsp;&nbsp;
      <span class="cb"></span>Khác
    </td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="58mm">Có dự định sinh con sắp tới không:</td>
    <td>
      <span class="cb"></span>Có &nbsp;&nbsp;
      <span class="cb"></span>Không
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

{{-- ==================== TRANG 2-4: giữ PNG tạm (chưa có fill) ==================== --}}
<pagebreak margin-left="0" margin-right="0" margin-top="0" margin-bottom="0"/>
<img src="{{ public_path('downloads/plcp-pages/page-02.png') }}" style="position:absolute;left:0;top:0;width:210mm;height:297mm;">

<pagebreak margin-left="0" margin-right="0" margin-top="0" margin-bottom="0"/>
<img src="{{ public_path('downloads/plcp-pages/page-03.png') }}" style="position:absolute;left:0;top:0;width:210mm;height:297mm;">

<pagebreak margin-left="0" margin-right="0" margin-top="0" margin-bottom="0"/>
<img src="{{ public_path('downloads/plcp-pages/page-04.png') }}" style="position:absolute;left:0;top:0;width:210mm;height:297mm;">

</body>
</html>
