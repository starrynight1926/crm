<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>PLCP - {{ $ma_kh }}</title>
<style>
  @page {
    margin: 18mm 15mm 20mm 24mm;
    odd-header-name: html_sidebar;
    even-header-name: html_sidebar;
    odd-footer-name: html_footer;
    even-footer-name: html_footer;
  }
  body { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #111; line-height: 1.55; }

  /* Title block */
  h1 { font-size: 15pt; color: #6a4a1e; margin: 0 0 1mm 0; letter-spacing: 0.5px; font-weight: bold; text-transform: uppercase; }
  .subtitle { font-size: 12pt; color: #6a4a1e; margin: 0 0 6mm 0; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; }
  h2 { font-size: 11pt; color: #6a4a1e; margin: 6mm 0 3mm 0; letter-spacing: 0.4px; font-weight: bold; text-transform: uppercase; }

  /* Row/label/value */
  table.row { width: 100%; border-collapse: collapse; margin-bottom: 1.5mm; }
  table.row td { vertical-align: bottom; padding: 0.3mm 0; }
  .lbl { font-weight: bold; white-space: nowrap; padding-right: 2mm; }
  .val { border-bottom: 1px dotted #444; min-height: 4.5mm; padding-left: 1mm; padding-bottom: 0.3mm; }
  .val.filled { font-weight: bold; color: #000; }
  .col2 > tbody > tr > td { width: 50%; vertical-align: top; }
  .col2 > tbody > tr > td.right { padding-left: 5mm; }

  /* Checkbox */
  .cb { display: inline-block; width: 3.2mm; height: 3.2mm; border: 1px solid #333; vertical-align: middle; margin-right: 1.2mm; }
  .cb-list span { display: inline-block; margin-right: 7mm; margin-top: 1mm; }
  .cb-grid { width: 100%; border-collapse: collapse; margin-top: 1mm; }
  .cb-grid td { padding: 0.8mm 4mm 0.8mm 0; vertical-align: top; width: 25%; }
  .cb-grid.c3 td { width: 33.3%; }
  .cb-grid.c2 td { width: 50%; }

  /* Blank multi-line */
  .blank-lines div { border-bottom: 1px dotted #444; height: 4.5mm; margin-top: 1.5mm; }

  /* Footer */
  .doc-footer { text-align: right; font-weight: bold; font-size: 8.5pt; color: #6a4a1e; text-transform: uppercase; letter-spacing: 0.4px; }

  /* Left gradient bar via HTMLHeader — absolute positioning inside header */
  .sidebar {
    position: absolute;
    top: -18mm;
    left: -24mm;
    width: 6mm;
    height: 297mm;
    background-image: linear-gradient(180deg, #d4a94a 0%, #a17827 45%, #6a4a1e 100%);
  }
</style>
</head>
<body>

{{-- Dải màu vàng-nâu lề trái, lặp mọi trang qua htmlpageheader --}}
<htmlpageheader name="sidebar">
  <div class="sidebar"></div>
</htmlpageheader>
<htmlpagefooter name="footer">
  <div class="doc-footer">HỒ SƠ CHĂM SÓC SỨC KHỎE CÁ NHÂN HÓA - {PAGENO}</div>
</htmlpagefooter>

<h1>PERSONALIZED LONGEVITY CARE PROFILE (PLCP)</h1>
<div class="subtitle">HỒ SƠ CHĂM SÓC SỨC KHỎE CÁ NHÂN HÓA</div>

<table class="row col2">
  <tr>
    <td><table class="row"><tr><td class="lbl">Mã khách hàng:</td><td class="val filled">{{ $ma_kh }}</td></tr></table></td>
    <td class="right"><table class="row"><tr><td class="lbl">Ngày lập hồ sơ:</td><td class="val filled">{{ $ngay_lap }}</td></tr></table></td>
  </tr>
  <tr>
    <td colspan="2"><table class="row"><tr><td class="lbl" style="width:22mm">Cơ sở:</td><td class="val filled">{{ $co_so }}</td></tr></table></td>
  </tr>
  <tr>
    <td><table class="row"><tr><td class="lbl" style="width:60mm">Personalized Health Advisor (PHA):</td><td class="val">&nbsp;</td></tr></table></td>
    <td class="right"><table class="row"><tr><td class="lbl" style="width:35mm">Bác sĩ phụ trách:</td><td class="val">&nbsp;</td></tr></table></td>
  </tr>
</table>

<div style="margin-top:3mm; font-weight:bold;">NGUỒN KHÁCH HÀNG:</div>
<table class="cb-grid">
  <tr>
    <td><span class="cb"></span>Giới thiệu</td>
    <td><span class="cb"></span>Khách hàng hiện hữu</td>
    <td><span class="cb"></span>Đối tác</td>
    <td><span class="cb"></span>Sự kiện</td>
  </tr>
  <tr>
    <td><span class="cb"></span>Facebook</td>
    <td><span class="cb"></span>Website</td>
    <td><span class="cb"></span>Cuộc hẹn</td>
    <td><span class="cb"></span>Khác</td>
  </tr>
</table>

<h2>Phần 1: Thông tin cá nhân / Personal Information</h2>
<table class="row col2">
  <tr>
    <td><table class="row"><tr><td class="lbl" style="width:22mm">Họ và tên:</td><td class="val filled">{{ $ho_ten }}</td></tr></table></td>
    <td class="right"><table class="row"><tr><td class="lbl" style="width:22mm">Ngày sinh:</td><td class="val">&nbsp;</td></tr></table></td>
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
    <td class="right"><table class="row"><tr><td class="lbl" style="width:28mm">Số điện thoại:</td><td class="val">&nbsp;</td></tr></table></td>
  </tr>
</table>
<table class="row"><tr><td class="lbl" style="width:18mm">Email:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:18mm">Địa chỉ:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:28mm">Nghề nghiệp:</td><td class="val">&nbsp;</td></tr></table>

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
    <td><table class="row"><tr><td class="lbl" style="width:44mm">Người liên hệ khẩn cấp:</td><td class="val">&nbsp;</td></tr></table></td>
    <td class="right"><table class="row"><tr><td class="lbl" style="width:28mm">Số điện thoại:</td><td class="val">&nbsp;</td></tr></table></td>
  </tr>
</table>

<h2>Phần 2: Thông tin sức khỏe &amp; lối sống</h2>
<div>
  <span class="lbl">Chỉ số cơ bản:</span>
  <span style="margin-left:3mm">Chiều cao: <span style="display:inline-block; min-width:15mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:2mm">Cân nặng: <span style="display:inline-block; min-width:15mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:2mm">BMI: <span style="display:inline-block; min-width:12mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:2mm">Huyết áp: <span style="display:inline-block; min-width:16mm; border-bottom:1px dotted #444">&nbsp;</span></span>
  <span style="margin-left:2mm">Mạch: <span style="display:inline-block; min-width:12mm; border-bottom:1px dotted #444">&nbsp;</span></span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Hút thuốc:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Không</span>
  <span style="margin-left:6mm"><span class="cb"></span>Đã từng</span>
  <span style="margin-left:6mm"><span class="cb"></span>Hiện tại</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Rượu bia:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Không</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thỉnh thoảng</span>
  <span style="margin-left:6mm"><span class="cb"></span>Hàng tuần</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thường xuyên</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Mức độ căng thẳng:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Thấp</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trung bình</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Rất cao</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Mức độ bận rộn:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Thấp</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trung bình</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Rất cao</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Thời gian ngồi mỗi ngày:</span>
  <span style="margin-left:3mm"><span class="cb"></span>&lt;4 giờ</span>
  <span style="margin-left:6mm"><span class="cb"></span>4-8 giờ</span>
  <span style="margin-left:6mm"><span class="cb"></span>&gt;8 giờ</span>
</div>
<table class="row" style="margin-top:1.5mm"><tr><td class="lbl" style="width:42mm">Chất lượng giấc ngủ:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:42mm">Tần suất khám sức khỏe:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:42mm">Nơi khám sức khỏe:</td><td class="val">&nbsp;</td></tr></table>

<pagebreak />

{{-- Trang 2 --}}
<table class="row"><tr><td class="lbl" style="width:36mm">Vận động:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:36mm">Chế độ dinh dưỡng:</td><td class="val">&nbsp;</td></tr></table>

<h2>Phần 3 Thông tin tiền sử bệnh lý</h2>
<div class="lbl" style="margin-bottom:1mm">Bệnh lý hiện tại:</div>
<table class="cb-grid">
  <tr><td><span class="cb"></span>Huyết áp</td><td><span class="cb"></span>Mỡ máu</td><td><span class="cb"></span>Tiểu đường</td><td><span class="cb"></span>Gout</td></tr>
  <tr><td><span class="cb"></span>Alzheimer</td><td><span class="cb"></span>Parkinson</td><td><span class="cb"></span>Tự miễn</td><td><span class="cb"></span>Ung thư</td></tr>
  <tr><td><span class="cb"></span>Tim mạch</td><td><span class="cb"></span>Xương khớp</td><td><span class="cb"></span>Tiêu hoá</td><td><span class="cb"></span>Khác</td></tr>
</table>
<div class="blank-lines"><div></div><div></div><div></div></div>

<table class="row" style="margin-top:2mm"><tr><td class="lbl" style="width:44mm">Thời gian phát hiện bệnh:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:44mm">Thuốc đang sử dụng:</td><td class="val">&nbsp;</td></tr></table>
<div class="blank-lines"><div></div></div>
<table class="row" style="margin-top:1.5mm"><tr><td class="lbl" style="width:44mm">TPCN đang sử dụng:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:20mm">Dị ứng:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:44mm">Tiền sử phẫu thuật:</td><td class="val">&nbsp;</td></tr></table>
<table class="row"><tr><td class="lbl" style="width:38mm">Tiền sử gia đình :</td><td class="val">&nbsp;</td></tr></table>
<div class="blank-lines"><div></div></div>

<table class="row col2" style="margin-top:2mm">
  <tr>
    <td>
      <span class="lbl">Xét nghiệm gần nhất:</span>
      <span style="margin-left:3mm"><span class="lbl">Ngày:</span> <span class="val" style="display:inline-block; min-width:35mm">&nbsp;</span></span>
    </td>
    <td class="right"><table class="row"><tr><td class="lbl" style="width:28mm">Nơi thực hiện:</td><td class="val">&nbsp;</td></tr></table></td>
  </tr>
</table>

<h2>Phần 4 Mục tiêu sức khỏe</h2>
<div class="lbl">TOP 3 mục tiêu ưu tiên</div>
<table class="row" style="margin-top:2mm">
  <tr>
    <td style="width:33.3%"><span class="lbl">1:</span> <span class="val" style="display:inline-block; min-width:52mm">&nbsp;</span></td>
    <td style="width:33.3%"><span class="lbl">2:</span> <span class="val" style="display:inline-block; min-width:52mm">&nbsp;</span></td>
    <td style="width:33.3%"><span class="lbl">3:</span> <span class="val" style="display:inline-block; min-width:52mm">&nbsp;</span></td>
  </tr>
</table>

<h2>Phần 5 Hồ sơ thấu hiểu khách hàng (PHA)</h2>
<div class="lbl">Điều gì khiến khách hàng tìm đến Longevity Medical System?</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Điều khách hàng lo lắng nhất về sức khỏe hiện nay?</div>
<div class="blank-lines"><div></div></div>

<pagebreak />

{{-- Trang 3 --}}
<div class="lbl">Điều gì khiến khách hàng quyết định hành động vào thời điểm này?</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Khách hàng đã từng điều trị hoặc sử dụng dịch vụ tương tự ở đâu?</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Điều gì làm khách hàng hài lòng hoặc thất vọng?</div>
<div class="blank-lines"><div></div></div>

<div style="margin-top:3mm"><span class="lbl">Phong cách cá nhân:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Logic</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cảm xúc</span>
  <span style="margin-left:6mm"><span class="cb"></span>Chi tiết</span>
  <span style="margin-left:6mm"><span class="cb"></span>Quyết định nhanh</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cần thời gian cân nhắc</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Phong cách giao tiếp:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Muốn giải thích chi tiết</span>
  <span style="margin-left:6mm"><span class="cb"></span>Ngắn gọn, trọng tâm</span>
  <span style="margin-left:6mm"><span class="cb"></span>Ưa dữ liệu và bằng chứng</span>
</div>
<div style="margin-top:1mm; padding-left:38mm"><span class="cb"></span>Thích được hướng dẫn từng bước</div>
<div style="margin-top:1.5mm"><span class="lbl">Phương thức liên hệ:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Gọi điện</span>
  <span style="margin-left:6mm"><span class="cb"></span>Zalo</span>
  <span style="margin-left:6mm"><span class="cb"></span>Email</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trực tiếp</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Điều khách hàng KHÔNG mong muốn:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Sợ đau</span>
  <span style="margin-left:6mm"><span class="cb"></span>Sợ tác dụng phụ</span>
  <span style="margin-left:6mm"><span class="cb"></span>Sợ phẫu thuật</span>
  <span style="margin-left:6mm"><span class="cb"></span>Sợ kim tiêm</span>
</div>
<div style="margin-top:1mm; padding-left:60mm">
  <span class="cb"></span>Không muốn nghỉ làm
  <span style="margin-left:6mm"><span class="cb"></span>Không muốn mất nhiều thời gian</span>
  <span style="margin-left:6mm"><span class="cb"></span>Khác</span>
</div>
<div class="blank-lines" style="padding-left:60mm"><div></div></div>

<h2>Phần 6 Care Transition Note (PHA → Bác sĩ)</h2>
<div class="lbl" style="margin-top:1mm">Tóm tắt hồ sơ khách hàng</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Mục tiêu chính</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Nỗi lo chính</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Liệu pháp đề xuất</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Lưu ý giao tiếp</div>
<div class="blank-lines"><div></div></div>

<div style="margin-top:2mm"><span class="lbl">Lưu ý đặc biệt :</span>
  <span style="margin-left:3mm"><span class="cb"></span>Cần giải thích chi tiết</span>
  <span style="margin-left:6mm"><span class="cb"></span>Kỳ vọng cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Lo lắng nhiều</span>
</div>
<div style="margin-top:1mm; padding-left:34mm">
  <span class="cb"></span>Ra quyết định chậm
  <span style="margin-left:6mm"><span class="cb"></span>Nhạy cảm với chi phí</span>
  <span style="margin-left:6mm"><span class="cb"></span>Muốn trao đổi trực tiếp với bác sĩ</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Cảm xúc :</span>
  <span style="margin-left:3mm"><span class="cb"></span>Lo lắng cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Cầu toàn</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thiếu niềm tin</span>
  <span style="margin-left:6mm"><span class="cb"></span>Nóng vội</span>
  <span style="margin-left:6mm"><span class="cb"></span>Kỳ vọng cao</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Mức độ sẵn sàng điều trị :</span>
  <span style="margin-left:3mm"><span class="cb"></span>Cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trung bình</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thấp</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Mức độ phù hợp với chương trình đồng hành dài hạn:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Cao</span>
  <span style="margin-left:6mm"><span class="cb"></span>Trung bình</span>
  <span style="margin-left:6mm"><span class="cb"></span>Thấp</span>
</div>

<pagebreak />

{{-- Trang 4 --}}
<div class="lbl">Phân tích nguyên nhân gốc rễ</div>
<div class="blank-lines"><div></div></div>

<h2>Phần 7 Đánh giá lâm sàng (Bác sĩ)</h2>
<div class="lbl" style="margin-top:1mm">Chẩn đoán</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Ấn tượng lâm sàng</div>
<div class="blank-lines"><div></div></div>

<div class="lbl" style="margin-top:2mm">Phân tầng rủi ro :</div>
<table class="cb-grid">
  <tr>
    <td><span class="cb"></span>Nguy cơ thấp</td>
    <td><span class="cb"></span>Nguy cơ cần theo dõi</td>
    <td><span class="cb"></span>Có bệnh nền hoặc nhiều yếu tố nguy cơ</td>
    <td><span class="cb"></span>Nguy cơ cao</td>
  </tr>
</table>

<div class="lbl" style="margin-top:2mm">Lộ trình điều trị ưu tiên</div>
<div class="lbl" style="margin-top:2mm">Ưu tiên số 1</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Ưu tiên số 2</div>
<div class="blank-lines"><div></div></div>
<div class="lbl" style="margin-top:2mm">Ưu tiên số 3</div>
<div class="blank-lines"><div></div></div>

<h2>Phần 8 Kế hoạch chăm sóc liên tục (Bác sĩ + PHA)</h2>
<div style="margin-top:1mm"><span class="lbl">Mức độ theo dõi:</span>
  <span style="margin-left:3mm"><span class="cb"></span>Tiêu chuẩn</span>
  <span style="margin-left:6mm"><span class="cb"></span>Tăng cường</span>
  <span style="margin-left:6mm"><span class="cb"></span>VIP</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">Kế hoạch Follow-up:</span>
  <span style="margin-left:3mm"><span class="cb"></span>24h</span>
  <span style="margin-left:6mm"><span class="cb"></span>3 ngày</span>
  <span style="margin-left:6mm"><span class="cb"></span>7 ngày</span>
  <span style="margin-left:6mm"><span class="cb"></span>30 ngày</span>
  <span style="margin-left:6mm"><span class="cb"></span>Theo lịch cá nhân hóa</span>
</div>

<div class="lbl" style="margin-top:2mm">Trạng thái hành trình thành viên</div>
<div style="margin-top:1.5mm"><span class="lbl">1. Khách hàng Khởi đầu Hành trình</span> <span class="cb" style="margin-left:2mm"></span></div>
<div style="margin-top:1.5mm"><span class="lbl">2. Khách hàng Đồng hành:</span></div>
<div style="margin-top:1mm; padding-left:6mm">
  <span class="cb"></span>OneCare 365
  <span style="margin-left:8mm"><span class="cb"></span>Signature OneCare 365</span>
  <span style="margin-left:8mm"><span class="cb"></span>Longevity OneCare 365</span>
</div>
<div style="margin-top:1.5mm"><span class="lbl">3. Khách hàng Đã trải nghiệm</span> <span class="cb" style="margin-left:2mm"></span></div>

<div class="lbl" style="margin-top:2mm">Lịch tái khám</div>
<div class="blank-lines"><div></div></div>

</body>
</html>
