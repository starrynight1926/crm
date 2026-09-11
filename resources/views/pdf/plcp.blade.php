<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>PLCP - {{ $ma_kh }}</title>
<style>
  @page {
    margin: 18mm 15mm 15mm 22mm;
    header: html_pageheader;
  }
  body { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #111; line-height: 1.55; }

  /* Dải màu vàng-nâu bên trái (chạy suốt trang) */
  htmlpageheader#pageheader { position: absolute; top: 0; left: 0; }
  .brand-bar {
    position: absolute;
    left: -22mm; top: -18mm;
    width: 8mm; height: 297mm;
    background-image: linear-gradient(180deg, #d4a94a 0%, #a17827 50%, #6a4a1e 100%);
  }

  h1 { font-size: 15pt; color: #7a5a2b; margin: 0 0 1mm 0; letter-spacing: 0.5px; font-weight: bold; }
  .subtitle { font-size: 12pt; color: #7a5a2b; margin: 0 0 6mm 0; letter-spacing: 0.3px; font-weight: bold; }
  h2 {
    font-size: 11pt; color: #7a5a2b; margin: 6mm 0 3mm 0;
    letter-spacing: 0.4px; font-weight: bold;
    border-bottom: 1px solid #c9a25a; padding-bottom: 1mm;
    text-transform: uppercase;
  }

  table.row { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
  table.row td { vertical-align: bottom; padding: 0.5mm 0; }

  .lbl { font-weight: bold; white-space: nowrap; padding-right: 2mm; }
  .val { border-bottom: 1px dotted #444; min-height: 4.5mm; padding-left: 1mm; padding-bottom: 0.3mm; }
  .val.filled { font-weight: bold; color: #000; }
  .col2 > tbody > tr > td { width: 50%; vertical-align: top; }
  .col2 > tbody > tr > td.right { padding-left: 5mm; }

  .cb { display: inline-block; width: 3.2mm; height: 3.2mm; border: 1px solid #333; vertical-align: middle; margin-right: 1.2mm; }
  .cb-row { margin-top: 1.5mm; }
  .cb-row span { display: inline-block; margin-right: 7mm; }

  .sign-cell { text-align: center; }
  .sign-box { height: 22mm; }
  .sign-line { border-top: 1px solid #333; margin: 0 8mm; }
  .sign-note { font-size: 8pt; color: #666; }
</style>
</head>
<body>

<htmlpageheader name="pageheader">
  <div class="brand-bar"></div>
</htmlpageheader>

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

<h2>Phần 1: Thông tin cá nhân / Personal Information</h2>
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

<div style="margin-top:1.5mm">
  <span class="lbl">Tình trạng hôn nhân:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Độc thân</span>
  <span style="margin-left:6mm"><span class="cb"></span>Kết hôn</span>
  <span style="margin-left:6mm"><span class="cb"></span>Khác</span>
</div>
<div style="margin-top:1.5mm">
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

<h2>Phần 2: Thông tin sức khỏe & lối sống</h2>
<div>
  <span class="lbl">Chỉ số cơ bản:</span>
  <span style="margin-left:3mm">Chiều cao: <span style="display:inline-block; min-width:18mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:2mm">Cân nặng: <span style="display:inline-block; min-width:18mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:2mm">BMI: <span style="display:inline-block; min-width:13mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:2mm">Huyết áp: <span style="display:inline-block; min-width:18mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:2mm">Mạch: <span style="display:inline-block; min-width:13mm; border-bottom:1px dotted #444">&nbsp;</span></span>
</div>
<div style="margin-top:1.5mm">
  <span class="lbl">Hút thuốc:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Không</span>
  <span style="margin-left:6mm"><span class="cb"></span>Đã từng</span>
  <span style="margin-left:6mm"><span class="cb"></span>Hiện tại</span>
</div>
<div style="margin-top:1.5mm">
  <span class="lbl">Rượu bia:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Không</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thỉnh thoảng</span>
  <span style="margin-left:6mm"><span class="cb"></span>Hàng tuần</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thường xuyên</span>
</div>
<div style="margin-top:1.5mm">
  <span class="lbl">Mức độ căng thẳng:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Thấp</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trung bình</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Rất cao</span>
</div>
<div style="margin-top:1.5mm">
  <span class="lbl">Mức độ bận rộn:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Thấp</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trung bình</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Rất cao</span>
</div>

<h2>Phần 3: Tiền sử y khoa & gia đình</h2>
<div>
  <div style="margin-bottom:2mm"><span class="lbl">Bệnh mãn tính đang mắc:</span> <span class="val" style="display:inline-block; min-width:100mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Thuốc đang dùng:</span> <span class="val" style="display:inline-block; min-width:110mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Dị ứng (thuốc/thực phẩm/khác):</span> <span class="val" style="display:inline-block; min-width:85mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Phẫu thuật/nhập viện gần đây:</span> <span class="val" style="display:inline-block; min-width:85mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Bệnh gia đình (bố/mẹ/anh chị em):</span> <span class="val" style="display:inline-block; min-width:80mm">&nbsp;</span></div>
</div>

<h2>Phần 4: Mong muốn & mục tiêu chăm sóc</h2>
<div>
  <div style="margin-bottom:2mm"><span class="lbl">Lý do đến hôm nay:</span> <span class="val" style="display:inline-block; min-width:110mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Mục tiêu sức khỏe 6-12 tháng tới:</span> <span class="val" style="display:inline-block; min-width:85mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Dịch vụ quan tâm:</span> <span class="val" style="display:inline-block; min-width:110mm">&nbsp;</span></div>
  <div style="margin-bottom:2mm"><span class="lbl">Ghi chú thêm:</span> <span class="val" style="display:inline-block; min-width:115mm">&nbsp;</span></div>
</div>

<table class="row col2" style="margin-top:8mm">
  <tr>
    <td class="sign-cell">
      <div class="lbl">Chữ ký khách hàng</div>
      <div class="sign-box"></div>
      <div class="sign-line">&nbsp;</div>
      <div class="sign-note">(Ký và ghi rõ họ tên)</div>
    </td>
    <td class="sign-cell right">
      <div class="lbl">Nhân viên tiếp đón</div>
      <div class="sign-box"></div>
      <div class="sign-line">&nbsp;</div>
      <div class="sign-note">(Ký và ghi rõ họ tên)</div>
    </td>
  </tr>
</table>

</body>
</html>
