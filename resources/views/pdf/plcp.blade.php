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
{{-- Mã khách hàng --}}
<div class="fill" style="left:47mm; top:48mm;">{{ $ma_kh }}</div>
{{-- Ngày lập hồ sơ --}}
<div class="fill" style="left:135mm; top:48mm;">{{ $ngay_lap }}</div>
{{-- Cơ sở --}}
<div class="fill" style="left:36mm; top:60mm;">{{ $co_so }}</div>
{{-- Họ và tên (trong Phần 1) --}}
<div class="fill" style="left:40mm; top:148mm;">{{ $ho_ten }}</div>

<pagebreak resetpagenum="1"/>
<img src="{{ public_path('downloads/plcp-pages/page-02.png') }}" class="page-bg">

<pagebreak/>
<img src="{{ public_path('downloads/plcp-pages/page-03.png') }}" class="page-bg">

<pagebreak/>
<img src="{{ public_path('downloads/plcp-pages/page-04.png') }}" class="page-bg">

</body>
</html>
