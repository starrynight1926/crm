<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>PLCP - {{ $ma_kh }}</title>
<style>
  @page { margin: 20mm 18mm; }
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.5; }
  h1 { font-size: 14pt; color: #7a5a2b; margin: 0 0 2mm 0; letter-spacing: 0.3px; }
  h2 { font-size: 12pt; color: #7a5a2b; margin: 6mm 0 3mm 0; letter-spacing: 0.3px; border-bottom: 1px solid #7a5a2b; padding-bottom: 1mm; }
  .subtitle { font-size: 12pt; color: #7a5a2b; margin: 0 0 6mm 0; letter-spacing: 0.2px; }
  table.row { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
  table.row td { vertical-align: bottom; padding: 1mm 0; }
  .lbl { font-weight: bold; white-space: nowrap; padding-right: 2mm; }
  .val { border-bottom: 1px dotted #444; min-height: 4.5mm; padding-left: 1mm; padding-bottom: 0.5mm; }
  .val.filled { font-weight: bold; color: #000; }
  .col2 td { width: 50%; }
  .col2 td.right { padding-left: 5mm; }
  .cb { display: inline-block; width: 3.5mm; height: 3.5mm; border: 1px solid #333; vertical-align: middle; margin-right: 1.5mm; }
  .cb-row { margin-top: 1.5mm; }
  .cb-row span { display: inline-block; margin-right: 8mm; }
  .brand-bar { position: fixed; left: 0; top: 0; bottom: 0; width: 6mm; background: linear-gradient(180deg,#c9a25a 0%,#7a5a2b 100%); }
</style>
</head>
<body>
<div class="brand-bar"></div>

<h1>PERSONALIZED LONGEVITY CARE PROFILE (PLCP)</h1>
<div class="subtitle">HỒ SƠ CHĂM SÓC SỨC KHỎE CÁ NHÂN HÓA</div>

<table class="row col2">
  <tr>
    <td>
      <table class="row"><tr>
        <td class="lbl">Mã khách hàng:</td>
        <td class="val filled">{{ $ma_kh }}</td>
      </tr></table>
    </td>
    <td class="right">
      <table class="row"><tr>
        <td class="lbl">Ngày lập hồ sơ:</td>
        <td class="val filled">{{ $ngay_lap }}</td>
      </tr></table>
    </td>
  </tr>
  <tr>
    <td colspan="2">
      <table class="row"><tr>
        <td class="lbl" style="width:22mm">Cơ sở:</td>
        <td class="val filled">{{ $co_so }}</td>
      </tr></table>
    </td>
  </tr>
  <tr>
    <td colspan="2">
      <table class="row"><tr>
        <td class="lbl" style="width:52mm">Personalized Health Advisor (PHA):</td>
        <td class="val">&nbsp;</td>
      </tr></table>
    </td>
  </tr>
  <tr>
    <td colspan="2">
      <table class="row"><tr>
        <td class="lbl" style="width:35mm">Bác sĩ phụ trách:</td>
        <td class="val">&nbsp;</td>
      </tr></table>
    </td>
  </tr>
</table>

<div style="margin-top:4mm; font-weight:bold;">NGUỒN KHÁCH HÀNG:</div>
<div class="cb-row">
  <span><span class="cb"></span>Giới thiệu</span>
  <span><span class="cb"></span>Khách hàng hiện hữu</span>
  <span><span class="cb"></span>Đối tác</span>
  <span><span class="cb"></span>Sự kiện</span>
</div>
<div class="cb-row">
  <span><span class="cb"></span>Facebook</span>
  <span><span class="cb"></span>Website</span>
  <span><span class="cb"></span>Cuộc hẹn</span>
  <span><span class="cb"></span>Khác</span>
</div>

<h2>PHẦN 1: THÔNG TIN CÁ NHÂN / PERSONAL INFORMATION</h2>
<table class="row col2">
  <tr>
    <td>
      <table class="row"><tr>
        <td class="lbl" style="width:22mm">Họ và tên:</td>
        <td class="val filled">{{ $ho_ten }}</td>
      </tr></table>
    </td>
    <td class="right">
      <table class="row"><tr>
        <td class="lbl" style="width:22mm">Ngày sinh:</td>
        <td class="val">&nbsp;</td>
      </tr></table>
    </td>
  </tr>
</table>

<table class="row col2">
  <tr>
    <td>
      <span class="lbl">Giới tính:</span>
      <span class="cb"></span>Nam
      <span class="cb" style="margin-left:4mm"></span>Nữ
      <span class="cb" style="margin-left:4mm"></span>Khác
    </td>
    <td class="right">
      <table class="row"><tr>
        <td class="lbl" style="width:28mm">Số điện thoại:</td>
        <td class="val">&nbsp;</td>
      </tr></table>
    </td>
  </tr>
</table>

<table class="row"><tr>
  <td class="lbl" style="width:18mm">Email:</td>
  <td class="val">&nbsp;</td>
</tr></table>
<table class="row"><tr>
  <td class="lbl" style="width:18mm">Địa chỉ:</td>
  <td class="val">&nbsp;</td>
</tr></table>
<table class="row"><tr>
  <td class="lbl" style="width:28mm">Nghề nghiệp:</td>
  <td class="val">&nbsp;</td>
</tr></table>

<div style="margin-top:2mm">
  <span class="lbl">Tình trạng hôn nhân:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Độc thân</span>
  <span style="margin-left:6mm"><span class="cb"></span>Kết hôn</span>
  <span style="margin-left:6mm"><span class="cb"></span>Khác</span>
</div>
<div style="margin-top:2mm">
  <span class="lbl">Có dự định sinh con sắp tới không:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Có</span>
  <span style="margin-left:6mm"><span class="cb"></span>Không</span>
</div>

<table class="row col2" style="margin-top:2mm">
  <tr>
    <td>
      <table class="row"><tr>
        <td class="lbl" style="width:44mm">Người liên hệ khẩn cấp:</td>
        <td class="val">&nbsp;</td>
      </tr></table>
    </td>
    <td class="right">
      <table class="row"><tr>
        <td class="lbl" style="width:28mm">Số điện thoại:</td>
        <td class="val">&nbsp;</td>
      </tr></table>
    </td>
  </tr>
</table>

<h2>PHẦN 2: THÔNG TIN SỨC KHỎE & LỐI SỐNG</h2>
<div>
  <span class="lbl">Chỉ số cơ bản:</span>
  <span style="margin-left:3mm">Chiều cao: <span style="display:inline-block; min-width:20mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:3mm">Cân nặng: <span style="display:inline-block; min-width:20mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:3mm">BMI: <span style="display:inline-block; min-width:15mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:3mm">Huyết áp: <span style="display:inline-block; min-width:20mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:3mm">Mạch: <span style="display:inline-block; min-width:15mm; border-bottom:1px dotted #444">&nbsp;</span></span>
</div>
<div style="margin-top:2mm">
  <span class="lbl">Hút thuốc:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Không</span>
  <span style="margin-left:6mm"><span class="cb"></span>Đã từng</span>
  <span style="margin-left:6mm"><span class="cb"></span>Hiện tại</span>
</div>
<div style="margin-top:2mm">
  <span class="lbl">Rượu bia:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Không</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thỉnh thoảng</span>
  <span style="margin-left:6mm"><span class="cb"></span>Hàng tuần</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thường xuyên</span>
</div>
<div style="margin-top:2mm">
  <span class="lbl">Mức độ căng thẳng:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Thấp</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trung bình</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Rất cao</span>
</div>
<div style="margin-top:2mm">
  <span class="lbl">Mức độ bận rộn:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Thấp</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trung bình</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Rất cao</span>
</div>

<h2>PHẦN 3: TIỀN SỬ Y KHOA & GIA ĐÌNH</h2>
<div style="min-height:35mm">
  <div style="margin-bottom:2mm"><span class="lbl">Bệnh mãn tính đang mắc:</span> <span class="val" style="display:inline-block; min-width:100mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Thuốc đang dùng:</span> <span class="val" style="display:inline-block; min-width:110mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Dị ứng (thuốc/thực phẩm/khác):</span> <span class="val" style="display:inline-block; min-width:85mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Phẫu thuật/nhập viện gần đây:</span> <span class="val" style="display:inline-block; min-width:85mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Bệnh gia đình (bố/mẹ/anh chị em):</span> <span class="val" style="display:inline-block; min-width:80mm">&nbsp;</span></div>
</div>

<h2>PHẦN 4: MONG MUỐN & MỤC TIÊU CHĂM SÓC</h2>
<div style="min-height:35mm">
  <div style="margin-bottom:2mm"><span class="lbl">Lý do đến hôm nay:</span> <span class="val" style="display:inline-block; min-width:110mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Mục tiêu sức khỏe 6-12 tháng tới:</span> <span class="val" style="display:inline-block; min-width:85mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Dịch vụ quan tâm:</span> <span class="val" style="display:inline-block; min-width:110mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Ghi chú thêm:</span> <span class="val" style="display:inline-block; min-width:115mm">&nbsp;</span></div>
</div>

<table class="row col2" style="margin-top:8mm">
  <tr>
    <td style="text-align:center">
      <div class="lbl">Chữ ký khách hàng</div>
      <div style="height:22mm"></div>
      <div style="border-top:1px solid #333; margin: 0 10mm">&nbsp;</div>
      <div style="font-size:8pt; color:#666">(Ký và ghi rõ họ tên)</div>
    </td>
    <td class="right" style="text-align:center">
      <div class="lbl">Nhân viên tiếp đón</div>
      <div style="height:22mm"></div>
      <div style="border-top:1px solid #333; margin: 0 10mm">&nbsp;</div>
      <div style="font-size:8pt; color:#666">(Ký và ghi rõ họ tên)</div>
    </td>
  </tr>
</table>

</body>
</html>
