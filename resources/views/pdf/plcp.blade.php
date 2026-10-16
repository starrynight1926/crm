<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>PLCP - {{ $ma_kh }}</title>
<style>
  @page { margin: 12mm 12mm 12mm 12mm; }
  body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #000; line-height: 1.35; }

  .title-lg { font-size: 18pt; font-weight: bold; color: #6b4a1e; margin: 0; letter-spacing: 0.5px; }
  .title-sm { font-size: 13pt; font-weight: bold; color: #6b4a1e; margin: 0 0 8mm 0; letter-spacing: 0.3px; }

  table.grid { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
  table.grid td { vertical-align: bottom; padding: 0; }

  .lbl { font-weight: normal; white-space: nowrap; padding-right: 2mm !important; }
  .val {
    font-weight: bold;
    border-bottom: 1px dotted #666;
    padding: 0 1mm 1mm 1mm !important;
    height: 5mm;
  }
  .val.empty { font-weight: normal; }

  .section-title { font-size: 10pt; font-weight: bold; margin: 4mm 0 2mm 0; text-transform: uppercase; }
  .section-title.part { font-size: 12pt; color: #6b4a1e; margin-top: 6mm; }

  table.checks { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
  table.checks td { padding: 1.5mm 0; font-size: 10pt; }
  .cb {
    display: inline-block;
    width: 3.5mm; height: 3.5mm;
    border: 1px solid #333;
    margin-right: 2mm;
    vertical-align: middle;
  }
</style>
</head>
<body>

{{-- ==================== TRANG 1 (rebuild HTML thuần) ==================== --}}

<p class="title-lg">PERSONALIZED LONGEVITY CARE PROFILE (PLCP)</p>
<p class="title-sm">HỒ SƠ CHĂM SÓC SỨC KHỎE CÁ NHÂN HÓA</p>

{{-- Row: Mã KH | Ngày lập --}}
<table class="grid">
  <tr>
    <td class="lbl" width="28mm">Mã khách hàng:</td>
    <td class="val" width="72mm">{{ $ma_kh }}</td>
    <td width="6mm"></td>
    <td class="lbl" width="30mm">Ngày lập hồ sơ:</td>
    <td class="val">{{ $ngay_lap }}</td>
  </tr>
</table>

{{-- Row: Cơ sở full-width --}}
<table class="grid">
  <tr>
    <td class="lbl" width="14mm">Cơ sở:</td>
    <td class="val">{{ $co_so }}</td>
  </tr>
</table>

{{-- Row: PHA | Bác sĩ phụ trách --}}
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

{{-- Phần 1 --}}
<p class="section-title part">PHẦN 1: THÔNG TIN CÁ NHÂN / PERSONAL INFORMATION</p>

<table class="grid">
  <tr>
    <td class="lbl" width="22mm">Họ và tên:</td>
    <td class="val" width="88mm">{{ $ho_ten }}</td>
    <td width="6mm"></td>
    <td class="lbl" width="22mm">Ngày sinh:</td>
    <td class="val">&nbsp;</td>
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
    <td class="val">&nbsp;</td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="14mm">Email:</td>
    <td class="val">&nbsp;</td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="lbl" width="14mm">Địa chỉ:</td>
    <td class="val">&nbsp;</td>
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
