<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>PLCP - {{ $ma_kh }}</title>
<style>
  @page { margin: 0; }
  body { margin: 0; padding: 0; font-family: dejavusans, sans-serif; font-size: 10pt; color: #000; }
  .page-bg { position: absolute; left: 0; top: 0; width: 210mm; height: 297mm; }
  .fill {
    position: absolute;
    font-weight: bold;
    color: #000;
    white-space: nowrap;
  }
</style>
</head>
<body>

{{-- ==== TRANG 1 ==== --}}
<img src="{{ public_path('downloads/plcp-pages/page-01.png') }}" class="page-bg">
{{-- Toạ độ (mm) đo từ PNG page-01 @180dpi bằng dò dòng dot-line.
     Điều chỉnh 4 giá trị dưới đây nếu còn lệch. --}}
{{-- Mã khách hàng (row 1 col trái, dots y≈37mm) --}}
<div class="fill" style="left:40mm; top:33mm;">{{ $ma_kh }}</div>
{{-- Ngày lập hồ sơ (row 1 col phải) --}}
<div class="fill" style="left:135mm; top:33mm;">{{ $ngay_lap }}</div>
{{-- Cơ sở (dòng full-width, dots y≈47mm) --}}
<div class="fill" style="left:28mm; top:44mm;">{{ $co_so }}</div>
{{-- Họ và tên (Phần 1, dots y≈107mm) --}}
<div class="fill" style="left:40mm; top:104mm;">{{ $ho_ten }}</div>

<pagebreak resetpagenum="1"/>
<img src="{{ public_path('downloads/plcp-pages/page-02.png') }}" class="page-bg">

<pagebreak/>
<img src="{{ public_path('downloads/plcp-pages/page-03.png') }}" class="page-bg">

<pagebreak/>
<img src="{{ public_path('downloads/plcp-pages/page-04.png') }}" class="page-bg">

</body>
</html>
