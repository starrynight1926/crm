<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\SbBacSi;
use App\Models\SbRoom;
use App\Models\SbService;
use Illuminate\Support\Facades\DB;

/**
 * Trang tra cứu mirror sbooking bên SCRM (sb_services / sb_rooms / sb_bac_si /
 * sb_dich_vu_phong). Dùng để admin test đã sync đủ chưa — chưa sync → cột trống.
 *
 * Cấu trúc bảng giống bên sbooking (route DanhMucDichVuController) để dễ đối chiếu:
 *   ID · Cơ sở · Tên · Thời gian · Nhóm · Là DV · Phòng thực hiện · Nhân sự thực hiện
 *
 * Cột "Nhân sự thực hiện" TẠM luôn "—" — SCRM chưa có mirror
 * sb_dich_vu_bac_si (pivot DV↔BS bên sbooking đã có). Coi như dấu hiệu cần
 * xây thêm mirror nếu sau muốn tra cứu đầy đủ.
 */
class DanhMucDichVuMirrorController extends Controller
{
    public function index()
    {
        // co_so mapping: sbooking_co_so_id → facility.name
        $coSoMap = Facility::whereNotNull('sbooking_co_so_id')
            ->pluck('name', 'sbooking_co_so_id');

        // sb_rooms theo id (dùng lookup từ pivot).
        $roomsById = SbRoom::pluck('ten', 'sbooking_id');

        // pivot sb_dich_vu_phong: [sbooking_dich_vu_id => [sbooking_phong_id...]]
        $pivotDvPhong = DB::table('sb_dich_vu_phong')
            ->select('sbooking_dich_vu_id', 'sbooking_phong_id')
            ->get()
            ->groupBy('sbooking_dich_vu_id')
            ->map(fn ($g) => $g->pluck('sbooking_phong_id')->all());

        $rows = SbService::orderBy('sbooking_co_so_id')->orderBy('ten')->get()
            ->map(function ($s) use ($coSoMap, $roomsById, $pivotDvPhong) {
                $phongIds = $pivotDvPhong->get($s->sbooking_id, []);
                $phongNames = collect($phongIds)->map(fn ($id) => $roomsById->get($id))->filter()->all();
                return (object) [
                    'id'         => $s->sbooking_id,
                    'co_so'      => $coSoMap->get($s->sbooking_co_so_id) ?? '?',
                    'ten'        => $s->ten,
                    'thoi_gian'  => $s->thoi_gian_phut,
                    'nhom'       => $s->thuoc_nhom,
                    'la_dv'      => $s->la_dich_vu,
                    'active'     => $s->active,
                    'phongs'     => $phongNames,
                    'bac_sis'    => [],  // TODO: chưa có mirror sb_dich_vu_bac_si
                    'synced_at'  => $s->synced_at,
                ];
            });

        $meta = [
            'total'        => $rows->count(),
            'active'       => $rows->where('active', true)->count(),
            'has_pivot_dv_phong' => DB::table('sb_dich_vu_phong')->count() > 0,
            'last_sync'    => SbService::max('synced_at'),
            'ktv_mirror'   => SbBacSi::count(),
        ];

        return view('admin.danh-muc-dich-vu-mirror', compact('rows', 'meta'));
    }
}
