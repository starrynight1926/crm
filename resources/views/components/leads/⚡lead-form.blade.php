<?php

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\BookingLog;
use App\Services\SbookingClient;
use App\Models\CallLog;
use App\Models\CustomField;
use App\Models\Facility;
use App\Models\Lead;
use App\Models\LeadCustomValue;
use App\Models\LeadPhaseClosure;
use App\Models\LeadStatusLog;
use App\Models\LeadTreatment;
use App\Models\LeadUpsell;
use App\Models\OrgUnit;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?Lead $lead = null;

    // 2026-08-04: refresh khi có callback booking từ sbooking (script inject qua edit.blade.php + Echo).
    #[On('refresh-lead')]
    public function refreshFromCallback(): void
    {
        if ($this->lead) {
            $this->lead->refresh();
            $this->bookingStatus = $this->lead->booking_status ?? 'not_booked';
        }
    }

    public string $name = '';

    public string $phone = '';

    public string $received_date = '';

    // Phase 6.20 — page/camp giờ là custom field cấp công ty (không còn property core)


    public string $insight = '';

    public string $link = '';

    public string $region = '';

    // --- INSIGHT ---
    public string $birthday = '';
    public string $address = '';
    public string $medical_history = '';
    public string $occupation = '';

    // --- Dịch vụ ---
    public string $service_name = '';

    // --- LIỆU TRÌNH (Phase 6.11 — dạng thẻ 1-N, mỗi lần bác sĩ + đánh giá riêng) ---
    /** @var array<int, array{performed_at: string, performing_doctor_id: string|int|null, quality_rating: string}> */
    public array $treatmentRows = [];

    // --- DV tiềm năng ---
    public string $potential_service = '';

    // --- UPSELL ---
    /** @var array<int, array{staff_member_id: string, service_id: string, amount: string}> */
    public array $upsellRows = [];

    /** Kho: '' = kho chung công ty | 'org:{id}' = kho chung phòng/team. */
    public string $poolTarget = '';

    // 2026-08-03: cascade chia số — 3 select thay cho dropdown flat.
    // poolCompanyMode=true → poolTarget='company'; else poolTarget='org:<lowest_selected>'.
    public bool $poolCompanyMode = false;
    public string $poolBranchId = '';
    public string $poolFacilityId = '';
    public string $poolDepartmentId = '';

    /**
     * 2026-08-05: MKT distribution mode.
     *   'auto'   = chia ngay từ UPS list (round-robin, theo cơ sở trực page).
     *   'pool'   = thả lead vào kho theo cấp quyền cao nhất (Cơ sở / Chi nhánh / Công ty).
     *   'manual' = CM chia thẳng cho 1 nhân sự trong scope (perm lead.assign_direct).
     */
    public string $mktMode = 'auto';

    /**
     * 2026-08-10: Admin@longevity chọn cơ sở nhận (fallback khi trucPageFacility null).
     * Chỉ dùng khi user là admin@longevity.com.vn (không map cơ sở duy nhất qua assignment).
     */
    public ?int $mktFacilityOverrideId = null;

    /** 2026-08-05: user_id nhân sự được CM chia thẳng khi mktMode='manual' (không qua UPS). */
    public ?int $manualAssignUserId = null;

    /** Chia trực tiếp cho cá nhân (ưu tiên hơn kho nếu có). */
    public ?int $personId = null;

    /** 2026-08-07 — Mặc định false = ÁP luật thu hồi. Tick = exempt lead khỏi luật (VD lead đặc biệt CM giữ tay). */
    public bool $skipRecall = false;

    /** Ô search tên khi chia cá nhân. */
    public string $personSearch = '';

    public string $status_1 = '';

    public string $status_2 = '';

    public string $note = '';

    public string $classification = 'new';

    /** Trạng thái đặt lịch booking (Chưa đặt / Đã đặt / Hẹn lại). */
    public string $bookingStatus = 'not_booked';

    /** 1 trong 7 nhóm nguồn (MKT / MKT BR / BDM / BOD / SA / BA / WI). */
    public string $sourceGroup = '';

    public ?int $facilityId = null;
    public ?int $doctorId = null;
    public ?int $consultant1Id = null;
    public ?int $consultant2Id = null;
    public ?int $consultant3Id = null;

    /** @var array<int, string> custom_field_id => value */
    public array $custom = [];

    public ?int $duplicateLeadId = null;

    // ============================================================
    // Phase 6.21 — Customer Flow 7 phase state + methods (2026-07-30)
    // ============================================================

    /** Tab phase đang active (1..7). Alpine wire :phase. */
    public int $activePhase = 1;

    /** Đến lần đầu — bind vào leads.is_first_visit. */
    public bool $isFirstVisit = true;

    // State form thêm call log
    public string $newCallStatus = 'thanh_cong';
    public string $newCallNote = '';

    // State form thêm booking log
    public string $newBookingType = ''; // '' = -- Chọn --, 3 bucket: kham_ls | tu_van | dich_vu (2026-08-09 tách "tham_kham" thành 2 bucket con). Map sang booking_logs.type (tham_kham|dich_vu) khi save.
    /** @var array<int, array{gio_bat_dau: string, label: string}> Phase C1.d: slot đọc từ sbooking per phong */
    public array $availableSlots = [];
    /** @var array<int, array{id:int, ten:string, so_slot_toi_da:int, kieu_phong:?string}> Phase C1.d: phòng của cơ sở */
    public array $availableRooms = [];
    /** @var array{booked:int, capacity:int, full:bool}|null Trạng thái slot của phòng đang chọn tại (ngày, giờ) — load live từ sbooking */
    public ?array $roomStatus = null;
    public string $newBookingStatus = 'cho_xac_nhan';
    public string $newBookingScheduledAt = '';
    public string $newBookingDate = ''; // Phase C1.d rev2: tách date + time để wire:model.live sạch, combine ở save
    public string $newBookingTime = '';
    public ?int $newBookingFacilityId = null;
    public ?int $newBookingRoomId = null; // Phase C1.d: sb_rooms.sbooking_id
    public ?int $newBookingSbBacSiId = null; // Phase C1.d: sb_bac_si.sbooking_id (thay newBookingDoctorId cũ)
    public ?int $newBookingDoctorId = null; // giữ compat cho code cũ, không set từ form nữa
    public ?int $newBookingServiceId = null;
    public string $newBookingNote = '';
    // Phase C1.b rev9 2026-08-02: 4 field bổ sung đồng bộ với sbooking.
    public string $newBookingSoLieuTrinh = '';
    public string $newBookingSoLuongLo = '';
    public string $newBookingDungTichLo = '';
    public bool $newBookingKetHopMedical = false;
    public bool $newBookingCoTuVan = false;
    public bool $newBookingCoKhamCls = false;
    /** @var array<int, int|null> Phase 4 rework 2026-08-01: multi-CV per booking. Mặc định 1 ô. */
    public array $newBookingConsultantIds = [null];

    // 2026-08-04 (Task 2): filter trong "Lịch sử booking". '' = tất cả.
    public string $bookingHistoryFilter = '';

    // 2026-08-05: BỎ 4 field check-in — data giờ đồng bộ từ sbooking (BookingEventController auto-close phase 5).

    /**
     * 2026-08-03 bug #5: CV-only (chỉ được gán vào booking, không role quản lý) chỉ được viết
     * bình luận + phản hồi khách. Mọi action destructive khác 403. Gọi ở đầu action method.
     */
    private function guardNotCvOnly(): void
    {
        if ($this->lead && $this->lead->isCvViewerOnly(auth()->user())) {
            abort(403, 'Bạn là chuyên viên tư vấn (CV) — chỉ được viết bình luận/phản hồi khách. Liên hệ CM/Admin để chỉnh thông tin.');
        }
    }

    public function getIsCvOnlyProperty(): bool
    {
        return $this->lead && auth()->check() && $this->lead->isCvViewerOnly(auth()->user());
    }

    public function addCallLog(): void
    {
        $this->guardNotCvOnly();
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa được tạo. Bấm "Lưu thông tin khách hàng" trước.');
            return;
        }
        $user = auth()->user();
        if (! $this->lead->canLogCall($user)) {
            session()->flash('cf_error', 'Bạn không có quyền ghi log gọi.');
            return;
        }
        $this->validate([
            'newCallStatus' => 'required|in:' . implode(',', array_keys(CallLog::STATUSES)),
            'newCallNote'   => 'nullable|string|max:1000',
        ]);
        $note = $this->newCallNote ?: null;
        $dup = CallLog::where('lead_id', $this->lead->id)
            ->where('user_id', $user->id)
            ->where('status', $this->newCallStatus)
            ->where(fn ($q) => $note === null ? $q->whereNull('note') : $q->where('note', $note))
            ->where('called_at', '>=', now()->subSeconds(3))
            ->exists();
        if ($dup) {
            $this->newCallNote = '';
            session()->flash('cf_ok', 'Đã bỏ qua ghi trùng cuộc gọi.');
            return;
        }
        CallLog::create([
            'lead_id'   => $this->lead->id,
            'user_id'   => $user->id,
            'status'    => $this->newCallStatus,
            'note'      => $note,
            'called_at' => now(),
        ]);
        $this->newCallNote = '';
        $this->lead->refresh();
        session()->flash('cf_ok', 'Đã ghi log cuộc gọi.');
    }

    public function addBookingLog(): void
    {
        $this->guardNotCvOnly();
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa được tạo. Bấm "Lưu thông tin khách hàng" trước.');
            return;
        }
        $user = auth()->user();
        if (! $this->lead->canLogBooking($user)) {
            session()->flash('cf_error', 'Bạn không có quyền ghi log booking.');
            return;
        }
        $this->validate([
            'newBookingType'          => 'required|in:kham_ls,tu_van,dich_vu',
            'newBookingStatus'        => 'required|in:' . implode(',', array_keys(BookingLog::STATUSES)),
            'newBookingScheduledAt'   => 'nullable|date',
            'newBookingFacilityId'    => 'required|exists:facilities,id',
            'newBookingRoomId'        => 'required|exists:sb_rooms,sbooking_id',
            'newBookingSbBacSiId'     => 'nullable|exists:sb_bac_si,sbooking_id',
            'newBookingServiceId'     => 'nullable|integer',
            'newBookingNote'          => 'nullable|string|max:1000',
            'newBookingSoLieuTrinh'   => 'nullable|string|max:40',
            'newBookingSoLuongLo'     => 'nullable|string|max:40',
            // Phase C1.b rev9 2026-08-02: khớp enum sbooking (BookingController::store validate).
            'newBookingDungTichLo'    => 'nullable|in:8M,10M,16M,20M,450M,1 LT,2 LT',
            'newBookingKetHopMedical' => 'boolean',
            'newBookingCoTuVan' => 'boolean',
            'newBookingCoKhamCls' => 'boolean',
            'newBookingConsultantIds' => 'array',
            'newBookingConsultantIds.*' => 'nullable|exists:users,id',
        ], [
            'newBookingType.required' => 'Chọn loại booking (Khám lâm sàng / Tư vấn / Dịch vụ).',
            'newBookingFacilityId.required' => 'Chọn cơ sở — booking phải gắn cơ sở để đẩy sang sbooking.',
            'newBookingRoomId.required' => 'Chọn phòng — sbooking cần phòng để check capacity.',
        ]);
        // Phase C1.b 2026-08-01 rev: chặn cứng nếu cơ sở (hoặc cha nó) chưa map sbooking. Không cho ghi log local.
        $facilityForCheck = \App\Models\Facility::find($this->newBookingFacilityId);
        $sbCoSoResolved = null;
        $walk = $facilityForCheck;
        while ($walk) {
            if ($walk->sbooking_co_so_id) { $sbCoSoResolved = (int) $walk->sbooking_co_so_id; break; }
            $walk = $walk->parent_id ? \App\Models\Facility::find($walk->parent_id) : null;
        }
        if (! $sbCoSoResolved) {
            session()->flash('cf_error', 'Cơ sở "' . ($facilityForCheck?->name ?? '?') . '" (hoặc cơ sở cha) chưa được kết nối sbooking. Vào Thiết lập → Kết nối Booking để map sbooking_co_so_id trước khi ghi booking.');
            return;
        }
        // 2026-08-05: CV auto lấy từ UPS Sale list (pickGreet round-robin bucket A→B→C→OFF).
        // Số CV = số slot user thêm. UPS list rỗng → block; wrap-around khi all busy đã có trong pickGreet.
        $poolFac = $this->trucPageFacility();
        if (! $poolFac) {
            session()->flash('cf_error', $this->isAdminLongevity()
                ? 'Chọn "Cơ sở UPS (admin)" ở đầu khối Ghi nhận booking trước khi lưu.'
                : 'Không xác định được cơ sở UPS của bạn — liên hệ Admin kiểm tra phân quyền (org_pool_map).');
            return;
        }
        $slotCount = max(1, count($this->newBookingConsultantIds));
        $ups = app(\App\Services\Ups\UpsDispatcher::class);
        $pickedCvIds = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $picked = $ups->pickGreet($poolFac->id);
            if (! $picked) {
                session()->flash('cf_error', 'Chưa có UPS list Sale hôm nay ở cơ sở "'.$poolFac->name.'" — không tạo được booking. Liên hệ Admin BO chốt UPS list trước.');
                return;
            }
            $pickedCvIds[] = $picked->id;
        }
        $this->newBookingConsultantIds = $pickedCvIds;
        // Combine date + time thành scheduled_at + parse end time + khung_gio_id.
        // 2026-08-03 fix bug #2: newBookingTime từ dropdown mã hoá "kg_id|start|end" (start/end HH:mm).
        // Legacy fallback: "HH:mm" hoặc "HH:mm-HH:mm" (không có kg_id).
        $scheduledAt = null;
        $scheduledEndAt = null;
        $sbKhungGioId = null;
        if ($this->newBookingDate && $this->newBookingTime) {
            $tm = $this->newBookingTime;
            if (substr_count($tm, '|') === 2) {
                [$kgId, $start, $end] = explode('|', $tm);
                $sbKhungGioId = ((int) $kgId) ?: null;
                if ($start) $scheduledAt = $this->newBookingDate . ' ' . $start . ':00';
                if ($end)   $scheduledEndAt = $end . ':00';
            } elseif (str_contains($tm, '-')) {
                [$start, $end] = array_map('trim', explode('-', $tm, 2));
                $scheduledAt = $this->newBookingDate . ' ' . $start . ':00';
                $scheduledEndAt = strlen($end) === 5 ? $end . ':00' : $end;
            } else {
                $scheduledAt = $this->newBookingDate . ' ' . $tm . ':00';
            }
        } elseif ($this->newBookingScheduledAt) {
            $scheduledAt = $this->newBookingScheduledAt;
        }
        // 2026-08-03 fix bug #3: dropdown value = sb_services.sbooking_id (ưu tiên).
        // TRƯỚC ĐÂY check Service::find() trước → collision id (VD services.id=114 khác sb_services.sbooking_id=114) làm lưu sai service_id + mất sb_dich_vu_id.
        // Chỉ fallback sang scrm.services khi sb_services không có record match (legacy).
        $resolvedServiceId = null;
        $sbDichVuId = null;
        if ($this->newBookingServiceId) {
            $sb = \App\Models\SbService::where('sbooking_id', $this->newBookingServiceId)->first();
            if ($sb) {
                $sbDichVuId = (int) $sb->sbooking_id;
                $match = \App\Models\Service::where('name', $sb->ten)->first();
                $resolvedServiceId = $match?->id;
            } else {
                $direct = \App\Models\Service::find($this->newBookingServiceId);
                if ($direct) {
                    $resolvedServiceId = $direct->id;
                    $mirror = \App\Models\SbService::where('ten', $direct->name)->first();
                    $sbDichVuId = $mirror?->sbooking_id;
                }
            }
        }

        $bl = BookingLog::create([
            'lead_id'      => $this->lead->id,
            'user_id'      => $user->id,
            // 2026-08-09: booking_logs.type vẫn giữ enum cũ (tham_kham|dich_vu) để tương thích sbooking.
            //   Bucket UI 'kham_ls' + 'tu_van' đều map về 'tham_kham' (loai_dat_lich=phong_kham bên sbooking).
            'type'         => $this->newBookingType === 'dich_vu' ? 'dich_vu' : 'tham_kham',
            'status'       => $this->newBookingStatus,
            'scheduled_at' => $scheduledAt,
            'scheduled_end_at' => $scheduledEndAt,
            'facility_id'  => $this->newBookingFacilityId,
            'sb_phong_id'  => $this->newBookingRoomId,
            'sb_bac_si_id' => $this->newBookingSbBacSiId,
            'sb_dich_vu_id' => $sbDichVuId,
            'sb_khung_gio_id' => $sbKhungGioId,
            'doctor_id'    => null,
            'service_id'   => $resolvedServiceId,
            'note'         => $this->newBookingNote ?: null,
            'so_lieu_trinh' => $this->newBookingSoLieuTrinh ?: null,
            'so_luong_lo'   => $this->newBookingSoLuongLo ?: null,
            'dung_tich_lo'  => $this->newBookingDungTichLo ?: null,
            'ket_hop_medical' => $this->newBookingKetHopMedical,
            'co_tu_van' => $this->newBookingCoTuVan,
            'co_kham_cls' => $this->newBookingCoKhamCls,
        ]);
        // Phase 4 rework 2026-08-01: attach multi-CV pivot theo thứ tự chọn (position=1..n).
        $cvIds = array_values(array_filter($this->newBookingConsultantIds, fn ($v) => (int) $v > 0));
        $syncData = [];
        foreach ($cvIds as $i => $uid) {
            $syncData[(int) $uid] = ['position' => $i + 1];
        }
        if ($syncData) {
            $bl->syncConsultantsTracked($syncData);
        }
        BookingLog::syncLeadBookingStatus($this->lead->id);
        // Nếu booking đã duyệt + có CV1 + lead chưa có Sale → handoff CV1 thành Sale phụ trách.
        if ($bl->status === BookingLog::STATUS_DA_XAC_NHAN && ! empty($cvIds) && ! $this->lead->fresh()->owner_id) {
            $this->assignToSale((int) $cvIds[0], 1);
        }
        // Phase C1.b 2026-08-01 rev: mọi booking đều push sang sbooking (đã chặn cứng ở đầu).
        $bl->update(['sync_status' => 'pending']);
        $ok = app(SbookingClient::class)->pushBooking($bl->fresh());
        if (! $ok) {
            session()->flash('cf_warn', 'Đã ghi booking nhưng chưa đồng bộ được sang sbooking (' . $bl->fresh()->sync_error . '). Bấm "🔄 Thử lại" ở dòng booking để retry.');
        }
        $this->reset([
            'newBookingType', 'newBookingScheduledAt', 'newBookingDate', 'newBookingTime',
            'newBookingFacilityId', 'newBookingRoomId', 'newBookingSbBacSiId', 'newBookingDoctorId',
            'newBookingServiceId', 'newBookingNote',
            'newBookingSoLieuTrinh', 'newBookingSoLuongLo', 'newBookingDungTichLo', 'newBookingKetHopMedical',
            'newBookingCoTuVan', 'newBookingCoKhamCls',
            'availableRooms', 'availableSlots', 'roomStatus',
        ]);
        $this->newBookingConsultantIds = [null];

        // 2026-08-09: booking tạo xong → auto-close phase 3 (Booking thăm khám).
        //   Trước đó phải sync lead.phase từ closures (giống logic ở addCallLog auto-close).
        //   Cần perm phase.close.booking OR phase.rollback — Sale/CM/Admin đều có.
        $bookingClosed = false;
        if (! $this->lead->phaseClosures()->where('phase', Lead::CF_PHASE_BOOKING)->exists()) {
            try {
                $curPhase = (int) $this->lead->phase;
                if ($curPhase < Lead::CF_PHASE_BOOKING) {
                    for ($p = 1; $p < Lead::CF_PHASE_BOOKING; $p++) {
                        \App\Models\LeadPhaseClosure::firstOrCreate(
                            ['lead_id' => $this->lead->id, 'phase' => $p],
                            ['closed_by' => $user->id, 'closed_at' => now(), 'note' => 'Auto: skip khi tạo booking phase 3'],
                        );
                    }
                    $this->lead->update(['phase' => Lead::CF_PHASE_BOOKING]);
                    $this->lead->refresh();
                }
                $this->lead->closePhase(Lead::CF_PHASE_BOOKING, $user, 'Auto: sau khi tạo booking');
                $bookingClosed = true;
            } catch (\Throwable $e) {
                session()->flash('cf_warn', 'Đã tạo booking nhưng không tự chốt phase 3: ' . $e->getMessage());
            }
        }

        $this->lead->refresh();
        $this->activePhase = min((int) $this->lead->phase, 4);
        $this->bookingStatus = $this->lead->booking_status ?? 'not_booked';

        $this->dispatch('scrm-swal',
            title: 'Đã tạo booking',
            text: $bookingClosed
                ? 'Booking đã ghi và đồng bộ. Phase 3 (Booking thăm khám) tự động đóng.'
                : 'Booking đã ghi và đồng bộ.',
            icon: 'success',
        );
        session()->flash('cf_ok', 'Đã ghi booking mới. Đã đồng bộ trạng thái.');
    }

    /**
     * Phase C1.e (2026-08-02) — inline edit note của 1 booking_log + auto push sang sbooking.
     */
    // Phase C1.f: comment thread state (form add + selected booking_log_id).
    public string $newCommentContent = '';
    public ?int $newCommentBookingLogId = null;
    public int $commentPage = 1;

    public function addComment(): void
    {
        if (! $this->lead) { session()->flash('cf_error', 'Chưa có lead.'); return; }
        $content = trim($this->newCommentContent);
        if ($content === '') { session()->flash('cf_error', 'Chưa nhập nội dung.'); return; }

        // Booking log: user chọn cụ thể hoặc mặc định = mới nhất.
        $bl = $this->newCommentBookingLogId
            ? \App\Models\BookingLog::where('id', $this->newCommentBookingLogId)->where('lead_id', $this->lead->id)->first()
            : $this->lead->bookingLogs()->latest('created_at')->first();
        if (! $bl) { session()->flash('cf_error', 'Chưa có booking log để gắn comment.'); return; }

        $user = auth()->user();
        \App\Models\BookingLogComment::create([
            'booking_log_id' => $bl->id,
            'lead_id' => $this->lead->id,
            'source' => 'scrm',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'content' => $content,
        ]);

        // Push sang sbooking nếu booking đã sync.
        if ($bl->sbooking_booking_id) {
            app(SbookingClient::class)->pushComment($bl->fresh(), $content, $user->id, $user->name);
        }

        $this->newCommentContent = '';
        session()->flash('cf_ok', 'Đã gửi bình luận.');
    }

    public array $editingBookingNotes = []; // booking_log_id => note text
    public ?int $editingBookingId = null;

    public function startEditBookingNote(int $bookingLogId): void
    {
        $bl = BookingLog::find($bookingLogId);
        if (! $bl || $bl->lead_id !== $this->lead?->id) return;
        $this->editingBookingId = $bookingLogId;
        $this->editingBookingNotes[$bookingLogId] = (string) $bl->note;
    }

    public function cancelEditBookingNote(): void
    {
        $this->editingBookingId = null;
    }

    public function saveBookingNote(int $bookingLogId): void
    {
        $this->guardNotCvOnly();
        $bl = BookingLog::find($bookingLogId);
        if (! $bl || $bl->lead_id !== $this->lead?->id) {
            session()->flash('cf_error', 'Booking không tồn tại.');
            return;
        }
        $newNote = trim((string) ($this->editingBookingNotes[$bookingLogId] ?? ''));
        $bl->update(['note' => $newNote ?: null]);
        $this->editingBookingId = null;

        if ($bl->sbooking_booking_id) {
            $ok = app(SbookingClient::class)->pushBookingUpdate($bl->fresh());
            session()->flash($ok ? 'cf_ok' : 'cf_warn',
                $ok ? 'Đã lưu ghi chú và đồng bộ sang sbooking.'
                    : 'Lưu ghi chú OK nhưng đồng bộ sbooking lỗi: ' . $bl->fresh()->sync_error);
        } else {
            session()->flash('cf_ok', 'Đã lưu ghi chú (chưa sync sbooking vì chưa có ma_booking).');
        }
        $this->lead->refresh();
    }

    public function retrySbookingSync(int $bookingLogId): void
    {
        $bl = BookingLog::find($bookingLogId);
        if (! $bl || $bl->lead_id !== $this->lead?->id) {
            session()->flash('cf_error', 'Booking không tồn tại.');
            return;
        }
        $bl->update(['sync_status' => 'pending']);
        $ok = app(SbookingClient::class)->pushBooking($bl->fresh());
        session()->flash($ok ? 'cf_ok' : 'cf_error',
            $ok ? 'Đã đồng bộ booking sang sbooking (id ' . $bl->fresh()->sbooking_booking_id . ').'
                : 'Vẫn không đồng bộ được: ' . $bl->fresh()->sync_error);
    }

    /**
     * Phase C1.d 2026-08-02: chọn cơ sở → load phòng từ sb_rooms.
     */
    public function updatedNewBookingFacilityId(mixed $value): void
    {
        $this->newBookingRoomId = null;
        $this->newBookingSbBacSiId = null;
        $this->availableRooms = [];
        $this->availableSlots = [];
        $this->roomStatus = null;
        if (! $value) return;

        $sbCoSoId = $this->resolveSbCoSoId((int) $value);
        if (! $sbCoSoId) return;

        // 2026-08-09: filter theo bucket (newBookingType).
        //   tu_van   → phòng phải có duoc_dat_tu_van=true (không giới hạn kieu_phong).
        //   kham_ls  → kieu_phong=phong_kham.
        //   dich_vu  → kieu_phong=phong_dich_vu.
        //   Chưa chọn bucket → hiện all phòng của cơ sở.
        $q = \App\Models\SbRoom::where('sbooking_co_so_id', $sbCoSoId)->where('trang_thai', 'hoat_dong');
        if ($this->newBookingType === 'tu_van') {
            $q->where('duoc_dat_tu_van', true);
        } elseif ($this->newBookingType === 'kham_ls') {
            $q->where('kieu_phong', 'phong_kham');
        } elseif ($this->newBookingType === 'dich_vu') {
            $q->where('kieu_phong', 'phong_dich_vu');
        }
        $this->availableRooms = $q->orderBy('ten')
            ->get(['sbooking_id as id', 'ten', 'so_slot_toi_da', 'kieu_phong', 'duoc_dat_tu_van'])
            ->map(fn ($r) => $r->toArray())
            ->all();
    }

    /** 2026-08-09: đổi bucket → reload phòng theo filter mới. */
    public function updatedNewBookingType(mixed $value): void
    {
        $this->newBookingServiceId = null;
        $this->newBookingRoomId = null;
        $this->newBookingSbBacSiId = null;
        if ($this->newBookingFacilityId) {
            $this->updatedNewBookingFacilityId($this->newBookingFacilityId);
        }
    }

    /** Đổi phòng / dịch vụ / ngày → reload slot theo (phong, dv, ngày). */
    public function updatedNewBookingRoomId(mixed $value): void { $this->loadSlotsAndStatus(); }
    public function updatedNewBookingServiceId(mixed $value): void { $this->loadSlotsAndStatus(); }
    public function updatedNewBookingDate(mixed $value): void { $this->loadSlotsAndStatus(); }
    public function updatedNewBookingTime(mixed $value): void { $this->refreshRoomStatus(); }

    /**
     * Gọi sbooking /sync/khung-gio?phong_id&dich_vu_id&ngay để lấy slot subdivided
     * theo thời lượng dịch vụ (5' khám lâm sàng, 30' tư vấn, v.v.).
     */
    private function loadSlotsAndStatus(): void
    {
        $this->availableSlots = [];
        $this->roomStatus = null;
        if (! $this->newBookingRoomId) return;

        // Resolve newBookingServiceId → sb_services.sbooking_id.
        //   - Nếu value đã là sb_services.sbooking_id → dùng luôn.
        //   - Nếu là scrm.services.id (legacy) → map qua name.
        $sbDichVuId = null;
        if ($this->newBookingServiceId) {
            if (\App\Models\SbService::where('sbooking_id', $this->newBookingServiceId)->exists()) {
                $sbDichVuId = (int) $this->newBookingServiceId;
            } else {
                $svc = \App\Models\Service::find($this->newBookingServiceId);
                if ($svc) {
                    $sbDichVuId = \App\Models\SbService::where('ten', $svc->name)->where('active', true)->value('sbooking_id');
                }
            }
        }

        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        $query = ['phong_id' => (int) $this->newBookingRoomId];
        if ($sbDichVuId) $query['dich_vu_id'] = (int) $sbDichVuId;
        if ($this->newBookingDate) $query['ngay'] = $this->newBookingDate;

        try {
            $r = \Illuminate\Support\Facades\Http::withToken($token)->timeout(6)->acceptJson()
                ->get($baseUrl . '/sync/khung-gio', $query);
            if ($r->successful()) {
                $this->availableSlots = $r->json('slots') ?? [];
            }
        } catch (\Throwable $e) {
            // Silent
        }
        $this->refreshRoomStatus();
    }

    /**
     * Gọi /sync/phong?co_so_id&ngay&gio để biết phòng đang chọn còn slot hay đã full.
     */
    private function refreshRoomStatus(): void
    {
        $this->roomStatus = null;
        if (! $this->newBookingRoomId || ! $this->newBookingFacilityId || ! $this->newBookingDate || ! $this->newBookingTime) return;

        $ngay = $this->newBookingDate;
        // newBookingTime có thể là "kg_id|start|end" (mới) hoặc "HH:mm" (legacy) — tách start.
        $tm = $this->newBookingTime;
        $gio = substr_count($tm, '|') === 2 ? explode('|', $tm)[1] : $tm;

        $sbCoSoId = $this->resolveSbCoSoId((int) $this->newBookingFacilityId);
        if (! $sbCoSoId) return;

        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        try {
            $r = \Illuminate\Support\Facades\Http::withToken($token)->timeout(6)->acceptJson()
                ->get($baseUrl . '/sync/phong', ['co_so_id' => $sbCoSoId, 'ngay' => $ngay, 'gio' => $gio]);
            if (! $r->successful()) return;
            foreach ($r->json('data') ?? [] as $room) {
                if ((int) ($room['id'] ?? 0) === (int) $this->newBookingRoomId) {
                    $this->roomStatus = [
                        'booked'   => (int) ($room['booked'] ?? 0),
                        'capacity' => (int) ($room['capacity'] ?? 1),
                        'full'     => (bool) ($room['full'] ?? false),
                    ];
                    return;
                }
            }
        } catch (\Throwable $e) {
            // silent
        }
    }

    private function resolveSbCoSoId(int $facilityId): ?int
    {
        $walk = \App\Models\Facility::find($facilityId);
        while ($walk) {
            if ($walk->sbooking_co_so_id) return (int) $walk->sbooking_co_so_id;
            $walk = $walk->parent_id ? \App\Models\Facility::find($walk->parent_id) : null;
        }
        return null;
    }

    public function addBookingConsultantSlot(): void
    {
        $this->guardNotCvOnly();
        $this->newBookingConsultantIds[] = null;
    }

    public function removeBookingConsultantSlot(int $index): void
    {
        if (count($this->newBookingConsultantIds) <= 1) return;
        unset($this->newBookingConsultantIds[$index]);
        $this->newBookingConsultantIds = array_values($this->newBookingConsultantIds);
    }

    public function bulkSavePhases(): void
    {
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa được tạo. Bấm "Lưu thông tin khách hàng" trước để tạo lead, rồi chốt phase.');
            return;
        }
        try {
            $closed = $this->lead->bulkSave(auth()->user());
            $this->lead->refresh();
            $this->lead->load('phaseClosures'); // Phase C1.b rev11: reload relation để breadcrumb đổi màu.
            $this->activePhase = min((int) $this->lead->phase, 4);
            session()->flash('cf_ok', 'Đã chốt ' . count($closed) . ' phase (' . min($closed) . '→' . max($closed) . ').');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function closePhaseNow(int $idx): void
    {
        $this->guardNotCvOnly();
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa tồn tại.');
            return;
        }
        try {
            // 2026-08-05: bỏ 4 field checkin — nếu manual close phase 5, closure note để null (sbooking là nguồn sự thật).
            $this->lead->closePhase($idx, auth()->user(), null);
            $this->lead->refresh();
            $this->lead->load('phaseClosures'); // Phase C1.b rev11: reload để breadcrumb đổi màu.
            $this->activePhase = min((int) $this->lead->phase, 4);
            session()->flash('cf_ok', 'Đã kết thúc phase ' . $idx . '.');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function rollbackToPhase(int $idx): void
    {
        if (! $this->lead) return;
        try {
            $this->lead->rollbackTo($idx, auth()->user());
            $this->lead->refresh();
            $this->activePhase = $idx;
            session()->flash('cf_ok', 'Đã lùi phase về ' . $idx . '.');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    /**
     * Phase 6.21i — Chuyển lead sang Sale phụ trách (chọn ở Phase 4).
     * Reuse pattern giống Phase 2 (chọn Tele), nhưng filter user theo role có lead.consult.
     */
    /**
     * Assign chuyên viên tư vấn (CV1 hoặc CV2) — dùng ở phase 4.
     * Rule (2026-08-01):
     *   - Yêu cầu booking_status = booked (rule cứng — không cho set sale nếu chưa booked).
     *   - Yêu cầu perm lead.distribute_sale / distribute / phase.rollback.
     *   - CV1 = handoff chính: set owner_id=userId, receiver_id=owner cũ (giữ tele lịch sử),
     *     pipeline_phase=sale, pipeline_status=in_care, consultant_1_id=userId. Auto-close P4.
     *   - CV2 = chỉ set consultant_2_id (phụ chăm sóc). Không đổi owner.
     */
    public function assignToSale(int $userId, int $slot = 1): void
    {
        $this->guardNotCvOnly();
        if (! $this->lead) {
            session()->flash('cf_error', 'Lead chưa tồn tại.');
            return;
        }
        if ($this->lead->booking_status !== Lead::BOOKING_BOOKED) {
            session()->flash('cf_error', 'Chưa đặt booking cho khách. Đặt booking + duyệt xong mới gán được chuyên viên tư vấn.');
            return;
        }
        $viewer = auth()->user();
        if (! $viewer->hasPermission('lead.distribute_sale')
            && ! $viewer->hasPermission('phase.rollback')
            && ! $viewer->hasPermission('lead.distribute')) {
            session()->flash('cf_error', 'Không có quyền phân bổ Sale (cần lead.distribute_sale).');
            return;
        }
        $sale = User::find($userId);
        if (! $sale) {
            session()->flash('cf_error', 'Không tìm thấy user.');
            return;
        }
        if ($slot === 2) {
            $this->lead->update(['consultant_2_id' => $sale->id]);
            $this->consultant2Id = $sale->id;
            $this->lead->refresh();
            session()->flash('cf_ok', 'Đã gán CV tư vấn 2: ' . $sale->name);
            return;
        }
        // CV1 — handoff chính
        $prevOwnerId = $this->lead->owner_id;
        $this->lead->update([
            'owner_id'         => $sale->id,
            'receiver_id'      => $prevOwnerId ?: $this->lead->receiver_id, // giữ tele cũ nếu có
            'assigned_at'      => now(),
            'pool_level'       => Lead::POOL_PERSONAL,
            'pipeline_phase'   => Lead::PHASE_SALE,
            'pipeline_status'  => Lead::PSTATUS_IN_CARE,
            'consultant_1_id'  => $sale->id,
            'org_unit_id'      => $sale->assignments->first()?->org_unit_id ?: $this->lead->org_unit_id,
        ]);
        // Auto-close phase 4 để indicator xanh.
        \App\Models\LeadPhaseClosure::updateOrCreate(
            ['lead_id' => $this->lead->id, 'phase' => Lead::CF_PHASE_BOOKING],
            ['closed_by' => $viewer->id, 'closed_at' => now(), 'note' => 'Auto-close: gán CV tư vấn 1']
        );
        if ((int) $this->lead->phase <= Lead::CF_PHASE_BOOKING) {
            $this->lead->update(['phase' => Lead::CF_PHASE_CHECKIN]);
        }
        $this->lead->refresh();
        $this->consultant1Id = $sale->id;
        LeadStatusLog::record($this->lead, 'assigned_sale', null, 'Gán CV1 + chuyển phase Sale: ' . $sale->name, $viewer->id);
        session()->flash('cf_ok', 'Đã gán CV tư vấn 1 + chuyển lead sang phase Sale: ' . $sale->name);
    }

    /**
     * Phase C1.f 2026-08-03 — pull booking từ sbooking cho lead này (khớp theo SĐT).
     * Upsert BookingLog theo sbooking_booking_id — dùng khi callback pushStatus bị miss
     * hoặc booking cũ tạo trước lúc setup callback.
     */
    public function syncBookingsFromExternal(): void
    {
        if (! $this->lead || ! $this->lead->phone) return;

        $token = config('services.booking.api_token');
        $baseUrl = rtrim(config('services.booking.api_url') ?: '', '/');
        if (! $token || ! $baseUrl) {
            session()->flash('cf_error', 'Chưa cấu hình BOOKING_URL / TOKEN (Thiết lập → Kết nối Booking).');
            return;
        }

        try {
            $r = \Illuminate\Support\Facades\Http::withToken($token)->timeout(15)->acceptJson()
                ->get($baseUrl . '/bookings', ['so_dien_thoai' => $this->lead->phone, 'per_page' => 100]);
        } catch (\Throwable $e) {
            session()->flash('cf_error', 'HTTP fail: ' . $e->getMessage());
            return;
        }
        if (! $r->successful()) {
            session()->flash('cf_error', 'HTTP ' . $r->status() . ': ' . substr($r->body(), 0, 300));
            return;
        }

        $rows = $r->json('data') ?? [];
        $syncMap = [
            'da_xong' => 'done',
            'da_duyet' => 'approved',
            'cho_duyet' => 'synced',
            'tu_choi' => 'rejected',
        ];
        $created = 0; $updated = 0;
        foreach ($rows as $b) {
            $scheduledAt = null;
            if (! empty($b['ngay_dat'])) {
                $ngay = substr((string) $b['ngay_dat'], 0, 10);
                $gio = ! empty($b['gio_thuc_hien']) ? substr((string) $b['gio_thuc_hien'], 0, 8) : '00:00:00';
                $scheduledAt = $ngay . ' ' . $gio;
            }
            $attrs = [
                'lead_id' => $this->lead->id,
                'user_id' => auth()->id(),
                'type' => ($b['loai_dat_lich'] ?? 'phong_kham') === 'dich_vu' ? 'dich_vu' : 'tham_kham',
                'status' => \App\Models\BookingLog::STATUS_CHO_XAC_NHAN,
                'scheduled_at' => $scheduledAt,
                'sb_phong_id' => $b['phong_id'] ?? null,
                'sb_bac_si_id' => $b['bac_si_id'] ?? null,
                'note' => $b['ghi_chu'] ?? null,
                'so_lieu_trinh' => $b['so_lieu_trinh'] ?? null,
                'so_luong_lo' => $b['so_luong_lo'] ?? null,
                'dung_tich_lo' => $b['dung_tich_lo'] ?? null,
                'ket_hop_medical' => (bool) ($b['ket_hop_medical'] ?? false),
                'co_tu_van' => (bool) ($b['co_tu_van'] ?? false),
                'co_kham_cls' => (bool) ($b['co_kham_cls'] ?? false),
                'sbooking_booking_ma' => $b['ma_booking'] ?? null,
                'sync_status' => $syncMap[$b['trang_thai'] ?? ''] ?? 'synced',
                'sync_error' => ($b['trang_thai'] ?? '') === 'tu_choi' ? ($b['ly_do_tu_choi'] ?? null) : null,
                'synced_at' => now(),
            ];

            $existing = \App\Models\BookingLog::where('sbooking_booking_id', $b['id'])->first();
            if ($existing) { $existing->update($attrs); $updated++; }
            else { \App\Models\BookingLog::create(array_merge(['sbooking_booking_id' => $b['id']], $attrs)); $created++; }
        }

        \App\Models\BookingLog::syncLeadBookingStatus($this->lead->id);
        $this->lead->refresh();
        session()->flash('cf_ok', "Đã đồng bộ: {$created} mới, {$updated} cập nhật (tổng " . count($rows) . " booking từ sbooking).");
    }

    public function markReturning(int $targetPhase = 3): void
    {
        if (! $this->lead) return;
        try {
            $this->lead->markReturning(auth()->user(), $targetPhase);
            $this->lead->refresh();
            $this->isFirstVisit = false;
            $this->activePhase = $targetPhase;
            session()->flash('cf_ok', $targetPhase === 3
                ? 'Đã khởi động cuộc gọi mới. Về phase 3 (Gọi điện).'
                : 'Đã khởi động đặt lịch mới. Về phase 4 (Booking).');
        } catch (\Throwable $e) {
            session()->flash('cf_error', $e->getMessage());
        }
    }

    public function selectPhaseTab(int $idx): void
    {
        if ($idx < 1 || $idx > 7) return;
        if ($this->lead && $this->lead->phaseState($idx) === 'skipped') return;
        $this->activePhase = $idx;
    }

    public function mount(?Lead $lead = null): void
    {
        if ($lead?->exists) {
            // Phase 6.21g — dùng canOpenEditForm để cho phép Team Tele (chỉ có lead.read_booking)
            // vào form ở chế độ readonly, có thể xem info + thao tác Customer Flow (call log, close phase).
            // Việc sửa info cá nhân vẫn bị chặn ở tầng save() qua canEditPersonalInfo.
            abort_unless($lead->canOpenEditForm(auth()->user()), 403,
                'Bạn không có quyền truy cập lead ở phase ' . ($lead->pipeline_phase ?? 'sale') . '.');
            $this->lead = $lead;
            $this->fillFromLead($lead);
            // Phase 6.21 — default tab = phase hiện tại (hoặc openFrom nếu bulk mode).
            // Phase C1.b rev4 2026-08-01: query ?phase=N override để "Lưu thông tin" nhảy thẳng section booking (phase 4).
            $queryPhase = (int) request()->query('phase', 0);
            if ($queryPhase >= 1 && $queryPhase <= 7) {
                $this->activePhase = $queryPhase;
            } else {
                $this->activePhase = $lead->isBulkOpen() ? $lead->openFrom() : (int) $lead->phase;
                if ($this->activePhase < 1 || $this->activePhase > 6) $this->activePhase = 1;
                // 2026-08-05: owner (Sale/Tele) mở lead → default tab Call (2) để ghi cuộc gọi ngay.
                // Chỉ khi phase hiện tại còn thấp (chưa qua Call) và owner có canLogCall.
                if ($lead->owner_id === auth()->id() && $this->activePhase < 2 && $lead->canLogCall(auth()->user())) {
                    $this->activePhase = 2;
                }
            }
            $this->isFirstVisit = (bool) $lead->is_first_visit;
        } else {
            $this->received_date = now()->toDateString();
            $this->activePhase = 1;
        }
    }

    private function fillFromLead(Lead $lead): void
    {
        $this->name = $lead->name;
        $this->phone = $lead->phone;
        $this->received_date = $lead->received_date->toDateString();
        // Phase 6.20 — page/camp giờ trong custom_values, sẽ hiện trong section Trường bổ sung
        $this->insight = $lead->insight ?? '';
        $this->link = $lead->link ?? '';
        $this->region = $lead->region ?? '';
        $this->personId = $lead->owner_id;
        if ($lead->owner_id) {
            $this->poolTarget = '';
        } elseif ($lead->pool_level === Lead::POOL_TEAM && ($lead->pool_unit_id ?? $lead->org_unit_id)) {
            // Phase 6.24 — ưu tiên pool_unit_id (mới); fallback org_unit_id (legacy).
            $this->poolTarget = 'org:' . ($lead->pool_unit_id ?? $lead->org_unit_id);
        } else {
            $this->poolTarget = 'company';
        }
        $this->syncPoolCascadeFromTarget();
        $this->skipRecall = (bool) ($lead->skip_recall ?? false);
        $this->status_1 = $lead->status_1 ?? '';
        $this->status_2 = $lead->status_2 ?? '';
        $this->note = $lead->note ?? '';
        $this->classification = $lead->classification;
        $this->bookingStatus = $lead->booking_status ?? 'not_booked';
        $this->sourceGroup = $lead->source_group ?? '';
        $this->facilityId = $lead->facility_id;
        $this->doctorId = $lead->doctor_id;
        $this->consultant1Id = $lead->consultant_1_id;
        $this->consultant2Id = $lead->consultant_2_id;
        $this->consultant3Id = $lead->consultant_3_id;

        // Trường mới
        $this->birthday = $lead->birthday?->toDateString() ?? '';
        $this->address = $lead->address ?? '';
        $this->medical_history = $lead->medical_history ?? '';
        $this->occupation = $lead->occupation ?? '';
        $this->service_name = $lead->service_name ?? '';
        $this->treatmentRows = $lead->treatments->map(fn ($t) => [
            'performed_at' => $t->performed_at?->toDateString() ?? '',
            'performing_doctor_id' => (string) ($t->performing_doctor_id ?? ''),
            'quality_rating' => $t->quality_rating ?? '',
        ])->all();
        $this->potential_service = $lead->potential_service ?? '';
        $this->upsellRows = $lead->upsells->map(fn ($u) => [
            'staff_member_id' => (string) ($u->staff_member_id ?? ''),
            'service_id' => (string) ($u->service_id ?? ''),
            'amount' => $u->amount ? number_format($u->amount, 0, '', '.') : '',
        ])->all();

        $this->custom = $lead->customValues->pluck('value', 'custom_field_id')
            ->map(fn ($v) => (string) $v)->all();
    }

    /** Chọn cá nhân → lead rời kho chung, chuyển hẳn sang kho cá nhân. */
    public function selectPerson(int $id): void
    {
        if ($this->assignableUserIds()->contains($id)) {
            $this->personId = $id;
            $this->personSearch = '';
            $this->poolTarget = '';
        }
    }

    public function clearPerson(): void
    {
        $this->personId = null;
    }

    public function addUpsellRow(): void
    {
        $this->guardNotCvOnly();
        $this->upsellRows[] = ['staff_member_id' => '', 'service_id' => '', 'amount' => ''];
    }

    public function removeUpsellRow(int $index): void
    {
        unset($this->upsellRows[$index]);
        $this->upsellRows = array_values($this->upsellRows);
    }

    public function addTreatmentRow(): void
    {
        $this->guardNotCvOnly();
        $this->treatmentRows[] = ['performed_at' => '', 'performing_doctor_id' => '', 'quality_rating' => ''];
    }

    public function removeTreatmentRow(int $index): void
    {
        unset($this->treatmentRows[$index]);
        $this->treatmentRows = array_values($this->treatmentRows);
    }

    /** Chọn kho (dù là kho chung công ty) thì bỏ chia cá nhân. */
    public function updatedPoolTarget(): void
    {
        $this->guardNotCvOnly();
        $this->personId = null;
    }

    // 2026-08-03 cascade chia số: 3 select drive poolTarget.
    public function updatedPoolCompanyMode(): void
    {
        $this->guardNotCvOnly();
        if ($this->poolCompanyMode) {
            $this->poolBranchId = $this->poolFacilityId = $this->poolDepartmentId = '';
            $this->poolTarget = 'company';
        } else {
            $this->poolTarget = '';
        }
        $this->personId = null;
    }

    public function updatedPoolBranchId(): void
    {
        $this->guardNotCvOnly();
        $this->poolFacilityId = $this->poolDepartmentId = '';
        $this->recomputePoolTargetFromCascade();
    }

    public function updatedPoolFacilityId(): void
    {
        $this->guardNotCvOnly();
        $this->poolDepartmentId = '';
        $this->recomputePoolTargetFromCascade();
    }

    public function updatedPoolDepartmentId(): void
    {
        $this->guardNotCvOnly();
        $this->recomputePoolTargetFromCascade();
    }

    private function recomputePoolTargetFromCascade(): void
    {
        $lowest = $this->poolDepartmentId ?: ($this->poolFacilityId ?: $this->poolBranchId);
        $this->poolTarget = $lowest ? ('org:' . $lowest) : '';
        $this->personId = null;
    }

    /** Init 3 select từ poolTarget hiện có (khi mở lead cũ). */
    private function syncPoolCascadeFromTarget(): void
    {
        $this->poolCompanyMode = ($this->poolTarget === 'company');
        $this->poolBranchId = $this->poolFacilityId = $this->poolDepartmentId = '';
        if (! str_starts_with($this->poolTarget, 'org:')) return;
        $id = (int) substr($this->poolTarget, 4);
        $node = \App\Models\PoolUnit::find($id);
        while ($node) {
            if ($node->kind === 'department') $this->poolDepartmentId = (string) $node->id;
            if ($node->kind === 'facility')   $this->poolFacilityId   = (string) $node->id;
            if ($node->kind === 'branch')     $this->poolBranchId     = (string) $node->id;
            $node = $node->parent_id ? \App\Models\PoolUnit::find($node->parent_id) : null;
        }
    }

    /** Org của một user theo assignment hiệu lực. */
    private function userOrgId(int $userId): ?int
    {
        return Assignment::where('user_id', $userId)->effective()->first()?->org_unit_id;
    }

    /** Org quyết định bộ trường tùy biến theo đích chia đang chọn. */
    private function targetOrgUnit(): ?OrgUnit
    {
        if ($this->personId) {
            $orgId = $this->userOrgId($this->personId);
            return $orgId ? OrgUnit::find($orgId) : null;
        }
        if (str_starts_with($this->poolTarget, 'org:')) {
            return OrgUnit::find((int) substr($this->poolTarget, 4));
        }

        return $this->lead?->org_unit_id ? $this->lead->orgUnit : null;
    }

    /**
     * 2026-08-05 — PoolUnit cơ sở của trực page (từ org_pool_map).
     * Trực page mỗi cơ sở = 1 tài khoản → thường đúng 1 cơ sở. Trả về null nếu 0 hoặc >1
     * (phân quyền lỗi — báo Admin).
     */
    private function trucPageFacility(): ?\App\Models\PoolUnit
    {
        // 2026-08-10: admin@longevity không thuộc cơ sở nào → cho phép override tay.
        if ($this->isAdminLongevity() && $this->mktFacilityOverrideId) {
            return \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
                ->where('id', $this->mktFacilityOverrideId)->first();
        }
        // 2026-08-11: admin chọn cơ sở trên navbar → auto-resolve facility từ AdminScope.
        if ($this->isAdminLongevity() && ! $this->mktFacilityOverrideId) {
            $scopeBranchId = \App\Support\AdminScope::currentBranchId();
            if ($scopeBranchId) {
                $scopeOrgIds = \App\Support\AdminScope::orgUnitIds() ?? [];
                if ($scopeOrgIds) {
                    $fac = \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
                        ->whereIn('id', function ($q) use ($scopeOrgIds) {
                            $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', $scopeOrgIds);
                        })->first();
                    if ($fac) return $fac;
                }
            }
        }

        $ancestorOrgIds = [];
        foreach (auth()->user()->effectiveAssignments() as $assignment) {
            foreach (array_filter(explode('/', trim((string) $assignment->orgUnit->path, '/'))) as $seg) {
                $ancestorOrgIds[(int) $seg] = true;
            }
        }
        if ($ancestorOrgIds === []) return null;

        $facilities = \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
            ->whereIn('id', function ($q) use ($ancestorOrgIds) {
                $q->select('pool_unit_id')->from('org_pool_map')->whereIn('org_unit_id', array_keys($ancestorOrgIds));
            })->get();

        return $facilities->count() === 1 ? $facilities->first() : null;
    }

    /** 2026-08-10: Gate cho admin master account (không map cơ sở qua assignment). */
    private function isAdminLongevity(): bool
    {
        return auth()->user()?->email === 'admin@longevity.com.vn';
    }

    /**
     * 2026-08-05 — Preview N sale kế tiếp trong UPS Sale list (bucket A→B→C→OFF) cho auto-CV booking.
     * KHÔNG update state ups_rr_state — chỉ dùng show trong UI.
     * Nếu tất cả bucket bận → wrap-around (bỏ qua is_busy, đúng logic pickGreet fallback).
     * Trả về [User1, User2, ...] tối đa $n phần tử; empty nếu UPS list rỗng.
     *
     * @return array<int, \App\Models\User>
     */
    private function previewNextGreets(int $facilityPoolUnitId, int $n = 3): array
    {
        $workDate = now()->toDateString();
        $sales = collect();
        foreach (\App\Services\Ups\UpsDispatcher::BUCKET_ORDER_GREET as $bucket) {
            $bucketSales = \App\Models\DailyAttendance::with('user')
                ->where('facility_pool_unit_id', $facilityPoolUnitId)
                ->whereDate('work_date', $workDate)
                ->where('list_bucket', $bucket)
                // 2026-08-10: dung_nhan_lead luôn skip.
                ->where('dung_nhan_lead', false)
                ->orderBy('checkin_at')
                ->get()->pluck('user')->filter();
            $sales = $sales->concat($bucketSales);
        }
        $sales = $sales->values();
        if ($sales->isEmpty()) return [];

        // Tách active (không busy) và busy — active trước, busy sau (wrap-around fallback).
        $active = $sales->filter(fn ($u) => ! \App\Models\DailyAttendance::where('user_id', $u->id)
            ->whereDate('work_date', $workDate)->value('is_busy'))->values();
        $ordered = $active->isEmpty() ? $sales : $active;

        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $result[] = $ordered[$i % $ordered->count()];
        }
        return $result;
    }

    /**
     * 2026-08-05 — Preview sale kế trong MKT List (KHÔNG update state ups_rr_state).
     * Dùng cho banner "Tự động" để user biết ai sẽ nhận trước khi bấm Lưu.
     * Trả về ['sale' => User, 'rotated' => bool] hoặc null nếu MKT List rỗng.
     *   rotated=true → tất cả sale đang busy, xoay vòng lại từ đầu.
     */
    private function previewMktNextSale(int $facilityPoolUnitId): ?array
    {
        $workDate = now()->toDateString();

        $baseQ = \App\Models\DailyAttendance::with('user')
            ->where('facility_pool_unit_id', $facilityPoolUnitId)
            ->whereDate('work_date', $workDate)
            ->where('is_mkt', true)
            // 2026-08-10: sale dừng nhận lead → loại tuyệt đối kể cả wrap-around.
            ->where('dung_nhan_lead', false)
            ->orderBy('checkin_at');

        // Ưu tiên sale rảnh; nếu hết → wrap-around bất chấp busy (giữ nguyên round-robin state).
        $free = (clone $baseQ)->where('is_busy', false)->get()->pluck('user')->filter()->values();
        $all  = (clone $baseQ)->get()->pluck('user')->filter()->values();
        if ($all->isEmpty()) return null;

        $rotated = $free->isEmpty();
        $sales = $rotated ? $all : $free;

        $state = \Illuminate\Support\Facades\DB::table('ups_rr_state')
            ->where('facility_pool_unit_id', $facilityPoolUnitId)
            ->where('work_date', $workDate)
            ->where('bucket', 'MKT')
            ->first();
        $lastIdx = -1;
        if ($state?->last_user_id) {
            foreach ($sales as $i => $s) {
                if ($s->id === $state->last_user_id) { $lastIdx = $i; break; }
            }
        }
        $picked = $sales[($lastIdx + 1) % $sales->count()];

        return ['sale' => $picked, 'rotated' => $rotated];
    }

    /**
     * 2026-08-05 — PoolUnit đích khi trực page chọn "Chia về kho".
     * Cấp kho do quyền quyết định (không cho tự chọn):
     *   - lead.distribute_company → kho Công ty (cao nhất).
     *   - lead.distribute_branch  → kho Chi nhánh của trực page.
     *   - mặc định                → kho Cơ sở của trực page (nhỏ nhất).
     */
    private function mktPoolTarget(): ?\App\Models\PoolUnit
    {
        $user = auth()->user();

        if ($user->hasPermission('lead.distribute_company')) {
            return \App\Models\PoolUnit::where('kind', 'company')->first();
        }

        $facility = $this->trucPageFacility();
        if (! $facility) return null;

        if ($user->hasPermission('lead.distribute_branch')) {
            $node = $facility;
            while ($node && $node->kind !== 'branch') $node = $node->parent;
            return $node;
        }

        return $facility;
    }

    /** Validate + trả về [field_id => value] chỉ gồm các trường áp dụng. Trả null nếu có lỗi. */
    private function validateCustomFields(): ?array
    {
        $fields = CustomField::applicableTo($this->targetOrgUnit());
        $clean = [];
        $hasError = false;

        foreach ($fields as $field) {
            if ($field->field_type === 'code' && ($field->rules['code_kind'] ?? '') === 'fixed') {
                continue;
            }

            $value = trim((string) ($this->custom[$field->id] ?? ''));

            if ($value === '') {
                if ($field->required) {
                    $this->addError("custom.{$field->id}", "Trường \"{$field->label}\" là bắt buộc.");
                    $hasError = true;
                    // Phase 6.23: custom field required nằm ở panel phase 2 (Gọi điện) → auto nhảy tab đó.
                    $this->activePhase = 2;
                }
                continue;
            }

            $rules = $field->rules ?? [];

            if ($field->field_type === 'number') {
                $num = str_replace(',', '.', $value);
                if (! is_numeric($num)) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" phải là số.");
                    $hasError = true;
                    continue;
                }
                $num = (float) $num;
                if (isset($rules['min']) && $num < $rules['min']) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" phải ≥ {$rules['min']}.");
                    $hasError = true;
                    continue;
                }
                if (isset($rules['max']) && $num > $rules['max']) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" phải ≤ {$rules['max']}.");
                    $hasError = true;
                    continue;
                }
            } elseif ($field->field_type === 'text') {
                if (isset($rules['maxlength']) && mb_strlen($value) > $rules['maxlength']) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" tối đa {$rules['maxlength']} ký tự.");
                    $hasError = true;
                    continue;
                }
            } elseif ($field->field_type === 'email') {
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" phải là email hợp lệ.");
                    $hasError = true;
                    continue;
                }
            } elseif ($field->field_type === 'select') {
                if (! in_array($value, $field->options ?? [], true)) {
                    $this->addError("custom.{$field->id}", "Giá trị không nằm trong danh sách của \"{$field->label}\".");
                    $hasError = true;
                    continue;
                }
            } elseif ($field->field_type === 'code') {
                $kind = $rules['code_kind'] ?? 'input';
                if ($kind === 'fixed') {
                    continue;
                }
                $value = CustomField::normalizeCode($value);
                if ($value === '') {
                    $this->addError("custom.{$field->id}", "\"{$field->label}\" cần chữ/số.");
                    $hasError = true;
                    continue;
                }
                if ($kind === 'select' && ! in_array($value, array_map([CustomField::class, 'normalizeCode'], $field->options ?? []), true)) {
                    $this->addError("custom.{$field->id}", "Mã không nằm trong danh sách của \"{$field->label}\".");
                    $hasError = true;
                    continue;
                }
            }

            $clean[$field->id] = $value;
        }

        return $hasError ? null : $clean;
    }

    private function parseAmount(string $raw): int
    {
        return (int) preg_replace('/\D/', '', $raw);
    }

    private function syncUpsells(Lead $lead): void
    {
        $lead->upsells()->delete();
        foreach ($this->upsellRows as $row) {
            if (empty($row['service_id'])) {
                continue;
            }
            LeadUpsell::create([
                'lead_id' => $lead->id,
                'staff_member_id' => $row['staff_member_id'] ?: null,
                'service_id' => $row['service_id'],
                'amount' => $this->parseAmount($row['amount'] ?? '0'),
            ]);
        }
    }

    /** Phase 6.11 — Sync liệu trình (delete + recreate theo thứ tự nhập). */
    private function syncTreatments(Lead $lead): void
    {
        $lead->treatments()->delete();
        $seq = 1;
        foreach ($this->treatmentRows as $row) {
            $hasContent = ($row['performed_at'] ?? '') !== ''
                || ($row['performing_doctor_id'] ?? '') !== ''
                || trim((string) ($row['quality_rating'] ?? '')) !== '';
            if (! $hasContent) {
                continue;
            }
            LeadTreatment::create([
                'lead_id' => $lead->id,
                'sequence' => $seq++,
                'performed_at' => $row['performed_at'] ?: null,
                'performing_doctor_id' => $row['performing_doctor_id'] ?: null,
                'quality_rating' => trim((string) ($row['quality_rating'] ?? '')) ?: null,
            ]);
        }
    }

    private function syncCustomValues(Lead $lead, array $clean): void
    {
        foreach ($clean as $fieldId => $value) {
            LeadCustomValue::updateOrCreate(
                ['lead_id' => $lead->id, 'custom_field_id' => $fieldId],
                ['value' => $value]
            );
        }
    }

    /** Phase C1.b rev4 2026-08-01: gộp save + chuyển section Booking (phase 4).
     *  2026-08-09: nếu user đang ở phase 2/3 và có quyền close → auto-close phase hiện tại
     *  để breadcrumb V xanh + advance tự nhiên (không cần bấm "Kết thúc phase" riêng).
     */
    public function saveAndGoToBooking(): void
    {
        $this->guardNotCvOnly();
        session()->put('go_to_booking_after_save', true);
        $prevPhase = $this->activePhase;
        $this->save();
        // Sau khi save thành công: auto-close tuần tự từ $lead->phase → $prevPhase + nhảy tab.
        // 2026-08-11 fix: Tele bấm "Lưu" từ tab phase 2 khi lead vẫn ở phase 1 (Trực Page vừa tạo)
        // → closePhase(2) throw "Chỉ chốt được phase hiện tại (đang ở phase 1)". Loop tuần tự để
        // Tele chốt phase 1 (phase.close.new) rồi phase 2 (phase.close.call).
        if ($this->lead?->exists && ! $this->getErrorBag()->isNotEmpty()) {
            $viewer = auth()->user();
            if ($prevPhase >= 2 && $prevPhase <= 3) {
                $startPhase = (int) $this->lead->phase;
                for ($p = $startPhase; $p <= $prevPhase; $p++) {
                    $perm = Lead::CF_PHASE_CLOSE_PERM[$p] ?? null;
                    if ($perm && ! $viewer->hasPermission($perm)) {
                        session()->flash('cf_error', "Thiếu quyền {$perm} để chốt phase {$p}.");
                        break;
                    }
                    try {
                        $this->lead->closePhase($p, $viewer, 'Auto-close khi bấm "Lưu thông tin"');
                        $this->lead->refresh();
                    } catch (\Throwable $e) {
                        session()->flash('cf_error', 'Không tự chốt được phase ' . $p . ': ' . $e->getMessage());
                        break;
                    }
                }
                $this->lead->load('phaseClosures');
            }
            $this->activePhase = 3;
        }
        session()->forget('go_to_booking_after_save');
    }

    public function save(): void
    {
        // Phase 6.20 — Gate write: readonly user (VD Team booking chỉ có lead.read_booking) không được phép lưu.
        if ($this->lead) {
            abort_unless($this->lead->canEditPersonalInfo(auth()->user()), 403);
        } else {
            abort_unless(auth()->user()->hasPermission('lead.create'), 403);
        }

        $this->duplicateLeadId = null;

        $allowedSources = Lead::allowedSourceGroupsFor(auth()->user());
        // Phase 6.21g — UPDATE mode: nếu lead đã có sourceGroup cũ ngoài quyền user
        // (VD Tele update lead nguồn MKT do người khác up), vẫn cho pass source hiện có.
        if ($this->lead?->exists && $this->sourceGroup && ! isset($allowedSources[$this->sourceGroup])) {
            $allowedSources[$this->sourceGroup] = $this->sourceGroup;
        }
        $this->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string',
            'received_date' => 'required|date',
            // 2026-08-11: bỏ required — Phân loại (classification core) không còn bắt buộc, default 'new'.
            'classification' => 'nullable|in:' . implode(',', array_keys(Lead::CLASSIFICATIONS)),
            'bookingStatus' => 'required|in:' . implode(',', array_keys(Lead::BOOKING_STATUSES)),
            'sourceGroup' => 'required|in:' . implode(',', array_keys($allowedSources)),
            'link' => 'nullable|string|max:500',
            'birthday' => 'nullable|date',
            'address' => 'nullable|string|max:500',
            'occupation' => 'nullable|string|max:150',
            'treatmentRows' => 'array',
            'treatmentRows.*.performed_at' => 'nullable|date',
            'treatmentRows.*.performing_doctor_id' => 'nullable|exists:staff_members,id',
            'treatmentRows.*.quality_rating' => 'nullable|string|max:2000',
            'upsellRows' => 'array',
            'upsellRows.*.service_id' => 'required|exists:services,id',
            'upsellRows.*.staff_member_id' => 'nullable|exists:staff_members,id',
            'upsellRows.*.amount' => 'required|string',
        ], [
            'upsellRows.*.service_id.required' => 'Chọn dịch vụ cho dòng upsell.',
            'upsellRows.*.amount.required' => 'Nhập số tiền cho dòng upsell.',
        ], ['name' => 'tên khách hàng', 'phone' => 'SĐT', 'received_date' => 'ngày', 'sourceGroup' => 'nhóm nguồn']);

        // Nhóm 2 (BOD/SA/BA) — sale nhận trực tiếp, không qua duyệt.
        // Sale nhân viên không có quyền chia số → tự động nhận lead do chính họ up.
        if (in_array($this->sourceGroup, [Lead::SOURCE_BOD, Lead::SOURCE_SA, Lead::SOURCE_BA], true) && ! $this->personId) {
            if (! auth()->user()->hasPermission('lead.distribute')) {
                $this->personId = auth()->id();
            } else {
                $this->addError('personId', 'Nguồn ' . (Lead::SOURCE_GROUPS[$this->sourceGroup] ?? '') . ': bắt buộc chọn sale nhận.');
                return;
            }
        }

        // Phase 6.25 — Auto-assign MKT từ MKT List UPS: chỉ chạy khi tạo mới, nguồn MKT, chưa chọn sale tay.
        // 2026-08-05 fix: Trực page mỗi cơ sở là 1 tài khoản riêng (VD Trực page 59NTN HN, Trực page 207NVT HCM).
        // Không bắt user chọn kho cấp Cơ sở nữa — resolve cơ sở từ scope assignment của họ.
        // Ưu tiên poolTarget nếu user có chọn org cụ thể, fallback về assignment.
        // 2026-08-05 — Nguồn MKT (trực page): 2 mode.
        //   auto → chia ngay từ MKT List UPS (round-robin theo cơ sở trực page).
        //   pool → thả kho, cấp kho theo quyền (distribute_company > distribute_branch > mặc định cơ sở).
        $mktAutoAssigned = false;
        if (! $this->lead?->exists && in_array($this->sourceGroup, [Lead::SOURCE_MKT, Lead::SOURCE_MKT_BR], true) && ! $this->personId) {
            $facility = $this->trucPageFacility();
            if (! $facility) {
                $this->addError('mktMode', $this->isAdminLongevity()
                    ? 'Chọn cơ sở tiếp nhận ở panel "Chia tự động — dự kiến" trước khi Lưu.'
                    : 'Tài khoản trực page không map được cơ sở duy nhất — liên hệ Admin kiểm tra org_pool_map.');
                return;
            }

            if ($this->mktMode === 'auto') {
                $picked = app(\App\Services\Ups\UpsDispatcher::class)->pickMkt($facility->id);
                if (! $picked) {
                    $this->addError('mktMode', 'Chưa có Sale nào trong MKT List UPS hôm nay ở cơ sở "'.$facility->name.'". Chuyển "Chia về kho" hoặc liên hệ BO chốt UPS.');
                    return;
                }
                $this->personId = $picked->id;
                $mktAutoAssigned = true;
                session()->flash('status', "MKT List: Đã chia lead cho {$picked->name} (round-robin).");
            } elseif ($this->mktMode === 'manual') {
                // 2026-08-05: CM chia thẳng (perm lead.assign_direct). Không set busy UPS.
                if (! auth()->user()->hasPermission('lead.assign_direct')) {
                    $this->addError('mktMode', 'Bạn không có quyền "Chia lead thẳng".');
                    return;
                }
                if (! $this->manualAssignUserId) {
                    $this->addError('manualAssignUserId', 'Chọn nhân sự để chia thẳng.');
                    return;
                }
                $target = \App\Models\User::find($this->manualAssignUserId);
                if (! $target) {
                    $this->addError('manualAssignUserId', 'Nhân sự không tồn tại.');
                    return;
                }
                // Chỉ được chia trong scope của user hiện tại (data_scope: visibleOrgUnitIds).
                $visibleOrgIds = auth()->user()->visibleOrgUnitIds();
                $targetOrgIds = $target->assignments()->pluck('org_unit_id')->all();
                if (! array_intersect($visibleOrgIds, $targetOrgIds)) {
                    $this->addError('manualAssignUserId', 'Nhân sự không thuộc phạm vi của bạn.');
                    return;
                }
                $this->personId = $target->id;
                $mktAutoAssigned = true; // skip validate assignableUsers
                session()->flash('status', "Chia thẳng: Đã giao lead cho {$target->name} (phase 2 gọi điện).");
            } else { // pool
                $target = $this->mktPoolTarget();
                if (! $target) {
                    $this->addError('mktMode', 'Không xác định được kho đích theo phân quyền — liên hệ Admin.');
                    return;
                }
                $this->poolTarget = 'org:' . $target->id;
                $mktPoolAssigned = true; // 2026-08-07: skip validate scope bên dưới — mktPoolTarget() đã tôn trọng phân quyền.
                session()->flash('status', "Đã chia lead vào kho {$target->name}. Chờ CM chia tiếp.");
            }
        }

        // 2026-08-05: skip validate nếu personId = owner đang giữ (owner sửa lead của chính họ,
        // không phải thao tác chia). Trước đó Sale nhận lead từ UPS + save = "Không thể chia cho nhân sự này"
        // vì assignableUsers lọc theo role Team Tele cho phase booking, không có Team sale.
        $keepOwner = $this->lead?->exists && $this->lead->owner_id === $this->personId;
        if (! $mktAutoAssigned && ! $keepOwner && $this->personId && ! $this->assignableUserIds()->contains($this->personId)) {
            $this->addError('personId', 'Không thể chia cho nhân sự này.');
            return;
        }
        // 2026-08-07: skip check pool scope khi trực page dùng mode=pool (mktPoolTarget đã lock theo perm).
        //   Lý do: poolTarget bây giờ mang pool_unit_id (cây Kho số) còn visibleOrgUnitIds() trả org_unit_id
        //   (cây tổ chức) — ID 2 bảng khác nhau, so sánh trực tiếp luôn miss → Trực Page bấm Lưu bị silent fail.
        if (! ($mktPoolAssigned ?? false) && ! $this->personId && str_starts_with($this->poolTarget, 'org:')
            && ! in_array((int) substr($this->poolTarget, 4), auth()->user()->visibleOrgUnitIds(), true)) {
            $this->addError('poolTarget', 'Phòng/team không nằm trong phạm vi của bạn.');
            return;
        }

        $cleanCustom = $this->validateCustomFields();
        if ($cleanCustom === null) {
            return;
        }

        $normalized = Lead::normalizePhone($this->phone);
        if (! $normalized) {
            $this->addError('phone', 'SĐT không hợp lệ (cần dạng 0XXXXXXXXX hoặc +84...).');
            return;
        }

        // Fix 2026-08-02: check withTrashed để bắt cả lead đã bị soft-delete
        // (unique index DB bao cả row deleted → không check trước sẽ hit SQL exception).
        $existing = Lead::withTrashed()->where('phone', $normalized)
            ->when($this->lead, fn ($q) => $q->where('id', '!=', $this->lead->id))
            ->first();
        if ($existing) {
            $this->duplicateLeadId = $existing->isVisibleTo(auth()->user()) ? $existing->id : null;
            $msg = $existing->trashed()
                ? 'SĐT này thuộc lead đã bị xóa (id ' . $existing->id . '). Cần khôi phục thay vì tạo mới — liên hệ Admin.'
                : 'SĐT này đã tồn tại trong hệ thống — không tạo lead trùng.';
            $this->addError('phone', $msg);
            return;
        }

        $attributes = [
            'name' => $this->name,
            'phone' => $normalized,
            'received_date' => $this->received_date,
            // Phase 6.20 — page/camp: giờ ghi vào lead_custom_values qua form Trường bổ sung
            'insight' => $this->insight ?: null,
            'link' => $this->link ?: null,
            'region' => $this->region ?: null,
            // Trực Page không được ghi Kết quả — giữ nguyên giá trị cũ nếu là Trực Page.
            'status_1' => auth()->user()->hasRole('Trực Page') && $this->lead
                ? $this->lead->status_1
                : ($this->status_1 ?: null),
            'status_2' => auth()->user()->hasRole('Trực Page') && $this->lead
                ? $this->lead->status_2
                : ($this->status_2 ?: null),
            'note' => $this->note ?: null,
            'classification' => $this->classification,
            'booking_status' => $this->bookingStatus,
            'facility_id' => $this->facilityId ?: null,
            'doctor_id' => $this->doctorId ?: null,
            'consultant_1_id' => $this->consultant1Id ?: null,
            'consultant_2_id' => $this->consultant2Id ?: null,
            'consultant_3_id' => $this->consultant3Id ?: null,
            // Trường mới
            'birthday' => $this->birthday ?: null,
            'address' => $this->address ?: null,
            'medical_history' => $this->medical_history ?: null,
            'occupation' => $this->occupation ?: null,
            'service_name' => $this->service_name ?: null,
            'potential_service' => $this->potential_service ?: null,
            'source_group' => $this->sourceGroup,
            'approval_status' => $this->sourceGroup === Lead::SOURCE_WI ? Lead::APPROVAL_PENDING : Lead::APPROVAL_NONE,
        ];

        if ($this->lead) {
            $this->updateLead($attributes, $cleanCustom);
        } else {
            $this->createLead($attributes, $cleanCustom);
        }
    }

    private function createLead(array $attributes, array $cleanCustom): void
    {
        $user = auth()->user();
        $attributes['receiver_id'] = $user->id;
        // Người tạo lead (dù nhập tay hay import Excel) đều được coi là "người nhập"
        // để có scope xem lại data sau khi engine chia cho sale khác (giống flow
        // trực page import Excel — thuộc [[imported-by-scope]]).
        $attributes['imported_by'] = $user->id;
        $pool = $this->poolAttributes();
        $attributes = array_merge($attributes, $pool);

        // Phase 6.8 — set pipeline_phase/status theo source_group + owner (giống initialPipelineFor).
        [$phase, $status] = Lead::initialPipelineFor($this->sourceGroup, $pool['owner_id'] ?? null);
        $attributes['pipeline_phase'] = $phase;
        $attributes['pipeline_status'] = $status;

        $lead = Lead::create($attributes);
        $this->syncCustomValues($lead, $cleanCustom);
        $this->syncUpsells($lead);
        $this->syncTreatments($lead);
        $lead->load('customValues');
        $lead->generateCode();

        // Auto-chốt phase 1 sau khi tạo lead (system event, không cần perm — Trực Page
        // không có phase.close.new nhưng vẫn tạo được lead → auto close cho họ).
        \App\Models\LeadPhaseClosure::updateOrCreate(
            ['lead_id' => $lead->id, 'phase' => Lead::CF_PHASE_NEW],
            ['closed_by' => $user->id, 'closed_at' => now(), 'note' => 'Auto-close khi tạo lead']
        );
        // 2026-08-05: giữ phase = 1 cho trực page (không có distribute) — để Sale tự mở phase 2 ghi call.
        //   CM/Admin có distribute → auto next tab.
        $canDistribute = $user->hasPermission('lead.distribute');
        if ($canDistribute) {
            $lead->update(['phase' => min(Lead::CF_PHASE_NEW + 1, 4)]);
        }

        LeadStatusLog::record($lead, 'created', null, 'Nhập tay bởi ' . $user->name, $user->id);
        AuditLog::record('create', $lead);

        session()->flash('status', 'Đã tạo lead mới.');

        // 2026-08-05 fix: mọi user tạo lead xong đều mở edit lead vừa tạo (không lùi về /create).
        // Session flag go_to_booking_after_save chỉ nhảy phase 3 nếu user CÓ quyền đặt booking
        // (Sale/Admin). Trực page (chỉ up lead) → giữ phase mặc định (1), không đá vào Booking tab.
        $params = ['lead' => $lead];
        if (session('go_to_booking_after_save')) {
            if ($user->hasPermission('lead.book_action')) {
                $params['phase'] = 3;
            }
            session()->forget('go_to_booking_after_save');
        }
        if ($lead->canOpenEditForm($user)) {
            $this->redirectRoute('leads.edit', $params);
        } elseif ($user->hasPermission('lead.view')) {
            $this->redirectRoute('leads.show', $lead);
        } else {
            $this->redirectRoute('leads.create');
        }
    }

    private function updateLead(array $attributes, array $cleanCustom): void
    {
        $lead = $this->lead;
        $user = auth()->user();

        foreach (['classification', 'status_1', 'status_2', 'note'] as $field) {
            $old = $lead->{$field};
            $new = $attributes[$field];
            if ($old !== $new) {
                LeadStatusLog::record($lead, $field, $old, $new, $user->id);
            }
        }

        $attributes = array_merge($attributes, $this->poolAttributes($lead));

        if (in_array('classification', array_keys($attributes)) && $attributes['classification'] !== $lead->classification) {
            $attributes['last_care_at'] = now();
        }

        // Handoff Booking↔Sale theo booking_status:
        // - Booking→Sale khi đổi sang "Đã đặt" (từ trạng thái khác).
        // - Sale→Booking khi đổi ngược sang "Chưa đặt". "Hẹn lại" giữ ở Team Sale (còn hiệu lực).
        if ($lead->booking_status !== 'booked' && $this->bookingStatus === 'booked') {
            $saleOrgId = $this->siblingByName($lead->org_unit_id, 'Team Booking', 'Team Sale');
            if ($saleOrgId) {
                $attributes['org_unit_id'] = $saleOrgId;
                $attributes['pool_level'] = Lead::POOL_TEAM;
                $attributes['owner_id'] = null;
                $attributes['assigned_at'] = null;
                LeadStatusLog::record($lead, 'handoff', 'booking', 'sale', $user->id);
            }
        } elseif ($lead->booking_status === 'booked' && $this->bookingStatus === 'not_booked') {
            $bookingOrgId = $this->siblingByName($lead->org_unit_id, 'Team Sale', 'Team Booking');
            if ($bookingOrgId) {
                $attributes['org_unit_id'] = $bookingOrgId;
                $attributes['pool_level'] = Lead::POOL_TEAM;
                $attributes['owner_id'] = null;
                $attributes['assigned_at'] = null;
                LeadStatusLog::record($lead, 'handoff', 'sale', 'booking', $user->id);
            }
        }

        $lead->update($attributes);
        $this->syncCustomValues($lead, $cleanCustom);
        $this->syncUpsells($lead);
        $this->syncTreatments($lead);
        $lead->load('customValues');
        $lead->generateCode();
        AuditLog::record('update', $lead);

        session()->flash('status', 'Đã cập nhật thông tin khách hàng.');
        // Phase 6.21g — ở lại form edit thay vì nhảy sang trang chi tiết.
        // 2026-08-09 fix: honor session flag "go_to_booking_after_save" như createLead — click "Lưu thông tin"
        // ở phase 2 (Gọi điện) phải nhảy sang phase 3 (Booking) nếu user có quyền đặt booking.
        $params = ['lead' => $lead];
        if (session('go_to_booking_after_save')) {
            if ($user->hasPermission('lead.book_action')) {
                $params['phase'] = 3;
            }
            session()->forget('go_to_booking_after_save');
        }
        $this->redirectRoute('leads.edit', $params);
    }

    /**
     * Tìm sibling có $targetName của node có $fromName cùng cha.
     * VD (23, 'Team Booking', 'Team Sale') → id của Team Sale cùng cha team-giang.
     */
    private function siblingByName(?int $fromOrgId, string $fromName, string $targetName): ?int
    {
        if (! $fromOrgId) {
            return null;
        }
        $node = \App\Models\OrgUnit::find($fromOrgId);
        if (! $node || $node->name !== $fromName || ! $node->parent_id) {
            return null;
        }

        return \App\Models\OrgUnit::where('parent_id', $node->parent_id)
            ->where('name', $targetName)
            ->value('id');
    }

    /** Thuộc tính owner/org/pool theo đích chia; $existing để giữ assigned_at khi owner không đổi. */
    private function poolAttributes(?Lead $existing = null): array
    {
        // 2026-08-03: sau khi chia số phase 2 (owner hoặc team), transition WAITING → IN_CARE để
        // pipeline label đổi "Chờ CM booking chia" → "Booking · Đang chăm sóc" / "Sale · Đang chăm sóc".
        if ($this->personId) {
            return [
                'owner_id' => $this->personId,
                'org_unit_id' => $this->userOrgId($this->personId),
                'pool_level' => Lead::POOL_PERSONAL,
                'assigned_at' => ($existing && $existing->owner_id === $this->personId) ? $existing->assigned_at : now(),
                'pipeline_status' => Lead::PSTATUS_IN_CARE,
                // 2026-08-07: mặc định áp thu hồi; tick "Không thu hồi" = exempt.
                'skip_recall' => $this->skipRecall,
            ];
        }
        if (str_starts_with($this->poolTarget, 'org:')) {
            // Phase 6.24 — poolTarget bây giờ mang pool_unit_id (cây Kho số), không còn org_unit_id.
            // Giữ prefix "org:" cho tương thích với code cũ khi parse.
            return [
                'owner_id' => null,
                'org_unit_id' => null,
                'pool_unit_id' => (int) substr($this->poolTarget, 4),
                'pool_level' => Lead::POOL_TEAM,
                'assigned_at' => null,
                'pipeline_status' => Lead::PSTATUS_IN_CARE,
            ];
        }

        // Fallback: nguồn "sale nhận trực tiếp" mà user không có perm distribute
        // (VD Sale up WI hoặc BOD/SA/BA chưa auto-set personId) → auto về kho team của user thao tác, chờ CM team chia.
        // Khớp với hint text "Bước tiếp theo: chia về kho team, chờ CM team sale chia."
        if (in_array($this->sourceGroup, [Lead::SOURCE_WI, Lead::SOURCE_BOD, Lead::SOURCE_SA, Lead::SOURCE_BA], true)
            && ! auth()->user()->hasPermission('lead.distribute')) {
            $userTeamOrg = $this->userOrgId(auth()->id());
            if ($userTeamOrg) {
                return ['owner_id' => null, 'org_unit_id' => $userTeamOrg, 'pool_level' => Lead::POOL_TEAM, 'assigned_at' => null];
            }
        }

        return ['owner_id' => null, 'org_unit_id' => null, 'pool_level' => Lead::POOL_COMMON, 'assigned_at' => null];
    }

    /**
     * Phase 6.9 — Danh sách chuyên viên tư vấn gán được cho lead.
     * Rule: user active + có permission `lead.update` + assignment.org_unit ∈ subtree của lead.org_unit
     * (nếu lead chưa có org_unit → dùng scope của người thao tác) + visible với người thao tác.
     */
    private function consultantUsers()
    {
        $viewer = auth()->user();
        $visibleOrgIds = $viewer->visibleOrgUnitIds();

        // Xác định "subtree hợp lệ" cho chuyên viên tư vấn: dùng team sale ROOT chứa lead.
        // Với lead ở Team Tele (id=6, path /1/2/3/4/6/) → team sale sibling ở depth 3 (id=4 = Team Giang).
        // Consultant nằm trong subtree Team Giang → mở rộng root lên cấp depth 3 (Team owner).
        $rootOrg = $this->targetOrgUnit();
        $subtreeIds = [];
        if ($rootOrg) {
            // Nếu lead ở sub-team (depth 4+), lùi lên depth 3 (team owner) làm root.
            $effectiveRoot = $rootOrg;
            while ($effectiveRoot && $effectiveRoot->depth > 3) {
                $effectiveRoot = $effectiveRoot->parent;
            }
            if ($effectiveRoot) {
                $subtreeIds = \App\Models\OrgUnit::where('path', 'like', $effectiveRoot->path . '%')->pluck('id')->all();
            }
        }
        if ($subtreeIds === []) $subtreeIds = $visibleOrgIds;

        // Phase 6.21g — Nếu viewer là owner/receiver của lead (đang giữ), bỏ intersect scope
        // (họ có quyền chọn consultant cho lead của mình dù scope viewer hẹp).
        $isOwnerViewing = $this->lead?->exists && (
            $this->lead->owner_id === $viewer->id
            || $this->lead->receiver_id === $viewer->id
        );
        $allowedOrgIds = $isOwnerViewing || $viewer->hasPermission(Lead::CF_ROLLBACK_PERM)
            ? array_values($subtreeIds)
            : array_values(array_intersect($subtreeIds ?: [], $visibleOrgIds ?: []));

        if ($allowedOrgIds === []) {
            return collect();
        }

        return User::where('status', User::STATUS_ACTIVE)
            ->whereHas('assignments', fn ($q) => $q->effective()
                ->whereIn('org_unit_id', $allowedOrgIds)
                ->whereHas('role.permissions', fn ($qq) => $qq->where('key', 'lead.consult')))
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /** Nhân sự có thể chia trực tiếp: trong phạm vi của người thao tác + chính mình. */
    private function assignableUsers()
    {
        $visibleOrgIds = auth()->user()->visibleOrgUnitIds();

        // Fix 2026-08-01: filter theo phase của lead — không được chia lead phase
        // Booking cho Sale hoặc phase Sale cho Tele. Cũng KHÔNG show CM (Tele/Sale),
        // vì CM là quản lý chia, không phải nhân viên nhận lead trực tiếp.
        //   Booking → whitelist role name "Team Tele"
        //   Sale    → whitelist role name "Sale" | "Team sale" | "Team sale ĐN"
        //     (mở rộng khi có role sale mới — bổ sung vào mảng $allowRoles).
        // 2026-08-05 fix: cả tạo mới lẫn update — chia số chỉ cho sale/tele.
        // Không được chia cho Admin / CM / TL (họ quản lý chia, không nhận lead).
        $saleRoles = ['Sale', 'Team sale', 'Team sale ĐN', 'Team Tele'];
        if ($this->lead?->exists) {
            $allowRoles = $this->lead->pipeline_phase === Lead::PHASE_BOOKING
                ? ['Team Tele']
                : ['Sale', 'Team sale', 'Team sale ĐN'];
        } else {
            $allowRoles = $saleRoles;
        }

        return User::where('status', User::STATUS_ACTIVE)
            ->where(fn ($q) => $q
                ->whereHas('assignments', fn ($qq) => $qq->effective()->when(
                    $visibleOrgIds !== [],
                    fn ($qqq) => $qqq->whereIn('org_unit_id', $visibleOrgIds)
                ))
                ->orWhere('id', auth()->id()))
            ->when($allowRoles, fn ($q) => $q->whereHas('assignments.role', fn ($qq) => $qq->whereIn('name', $allowRoles)))
            ->orderBy('name')
            ->get();
    }

    private function assignableUserIds()
    {
        return $this->assignableUsers()->pluck('id');
    }

    /**
     * Phase 6.24 — Kho có thể đưa lead vào = cây Kho số (PoolUnit) toàn bộ.
     * Sort: HN → DN → HCM → khác.
     */
    private function assignableOrgs()
    {
        $pools = \App\Models\PoolUnit::where('is_active', true)->orderBy('path')->get();
        $branchPriority = ['pool-branch-hn' => 1, 'pool-branch-dn' => 2, 'pool-branch-hcm' => 3];
        $byId = $pools->keyBy('id');

        return $pools->sortBy(function ($o) use ($branchPriority, $byId) {
            $priority = 0;
            foreach (array_filter(explode('/', $o->path)) as $pid) {
                $node = $byId->get((int) $pid);
                if ($node && $node->depth === 1) {
                    $priority = $branchPriority[$node->code] ?? 90;
                    break;
                }
            }
            return sprintf('%02d-%s', $priority, $o->path);
        })->values();
    }

    public function with(): array
    {
        // Phase 6.21g — lock rule:
        //   - Phase 6, 7 luôn lock (chưa build — chỉ cho xem).
        //   - Phase 1..5 đã có closure → lock cho user không có phase.rollback.
        // Bug 2026-08-01: bổ sung khoá theo perm — user không có quyền thao tác
        // phase đó (VD Admin cơ sở với phase 3) → lock luôn dù chưa có closure.
        $phaseLocked = [6 => true, 7 => true];
        $u = auth()->user();
        $canRollback = $u->hasPermission(Lead::CF_ROLLBACK_PERM);
        $canLogCallHere = $this->lead?->exists ? $this->lead->canLogCall($u) : true;
        $canLogBookingHere = $this->lead?->exists ? $this->lead->canLogBooking($u) : true;
        $canCheckinHere = $this->lead?->exists ? $this->lead->canCheckin($u) : true;
        if ($this->lead?->exists) {
            $closedPhases = $this->lead->phaseClosures->pluck('phase')->all();
            $canDistributeHere = $u->hasPermission('lead.distribute');
            for ($p = 1; $p <= 5; $p++) {
                // 2026-08-02: phase 2 (Chia số) — user có quyền chia thì luôn edit được
                // kể cả đã close (để chia lại nếu chia nhầm).
                $lockedByClosure = in_array($p, $closedPhases, true)
                    && ! $canRollback
                    && ! ($p === 2 && $canDistributeHere);
                $lockedByPerm = ($p === 3 && ! $canLogCallHere)
                    || ($p === 4 && ! $canLogBookingHere)
                    || ($p === 5 && ! $canCheckinHere);
                $phaseLocked[$p] = $lockedByClosure || $lockedByPerm;
            }
        } else {
            for ($p = 1; $p <= 5; $p++) $phaseLocked[$p] = false;
        }

        // Sale cũ (CV1 đã bị đổi khỏi booking) → toàn bộ form chỉ đọc.
        if ($this->lead?->exists && $this->lead->isPastConsultantFor($u)) {
            for ($p = 1; $p <= 7; $p++) $phaseLocked[$p] = true;
        }

        $users = $this->assignableUsers();

        // Phase 6.24 — poolTarget mang pool_unit_id (cây Kho số). Filter user thuộc org
        // đã map với pool đó (org_pool_map) + subtree.
        if ($this->poolTarget && str_starts_with($this->poolTarget, 'org:')) {
            $poolId = (int) substr($this->poolTarget, 4);
            $mappedOrgIds = \Illuminate\Support\Facades\DB::table('org_pool_map')
                ->where('pool_unit_id', $poolId)->pluck('org_unit_id')->all();
            if ($mappedOrgIds) {
                $subtreeIds = [];
                foreach (OrgUnit::whereIn('id', $mappedOrgIds)->get() as $root) {
                    $subtreeIds = array_merge($subtreeIds, $root->subtreeIds());
                }
                $subtreeIds = array_unique($subtreeIds);
                $users = $users->filter(fn ($u) => $u->assignments
                    ->pluck('org_unit_id')
                    ->intersect($subtreeIds)
                    ->isNotEmpty());
            } else {
                // Pool chưa có mapping → không sale nào thấy được
                $users = collect();
            }
        }

        $q = trim($this->personSearch);
        $results = ($q === '' ? $users : $users->filter(fn ($u) => str_contains(mb_strtolower($u->name), mb_strtolower($q))))
            ->take(15)->values();

        $facilities = Facility::with(['children' => fn ($q) => $q->active()])->roots()->active()->get();

        $allStaff = StaffMember::with('facility.parent')->active()->orderBy('name')->get();

        // Bác sĩ giữ từ staff_members (title, chuyển API booking sau).
        $staffTree = $facilities->map(fn ($fac) => [
            'name' => $fac->name,
            'depts' => $fac->children->map(fn ($dept) => [
                'id' => $dept->id,
                'name' => $dept->name,
                'doctors' => $allStaff->where('facility_id', $dept->id)->where('role', 'doctor')->map(fn ($s) => ['id' => $s->id, 'name' => $s->displayName()])->values()->all(),
            ])->all(),
        ])->all();

        // Phase 6.9 — Chuyên viên tư vấn = user có lead.update, trong subtree org_unit của lead
        // (kể cả lead chưa có org_unit_id → lấy trong scope của người thao tác), + visible cho user hiện tại.
        $consultantUsers = $this->consultantUsers();

        $assignedConsultantIds = array_filter([$this->consultant1Id, $this->consultant2Id, $this->consultant3Id]);
        // Nếu chuyên viên đã gán không nằm trong list (VD user chuyển team), vẫn phải hiển thị tên.
        $assignedConsultants = $assignedConsultantIds
            ? User::whereIn('id', $assignedConsultantIds)->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()
            : collect();

        return [
            'phaseLocked' => $phaseLocked,
            'assignableOrgs' => $this->assignableOrgs(),
            'personResults' => $results,
            'selectedPerson' => $this->personId ? $users->firstWhere('id', $this->personId) : null,
            // 2026-08-02: Admin hệ thống (perm phase.rollback) thấy TẤT CẢ custom field, không lọc theo org.
            'customFields' => auth()->user()->hasPermission(\App\Models\Lead::CF_ROLLBACK_PERM)
                ? CustomField::where('active', true)->where('status', CustomField::STATUS_ACTIVE)->orderBy('org_unit_id')->orderBy('position')->orderBy('id')->get()
                : CustomField::applicableTo($this->targetOrgUnit()),
            'facilities' => $facilities,
            // 2026-08-05: label kho đích cho radio "Chia về kho" (khi trực page up MKT).
            'mktPoolTargetLabel' => (! $this->lead?->exists && in_array($this->sourceGroup, [Lead::SOURCE_MKT, Lead::SOURCE_MKT_BR], true))
                ? ($this->mktPoolTarget()?->name)
                : null,
            'mktFacilityName' => (! $this->lead?->exists && in_array($this->sourceGroup, [Lead::SOURCE_MKT, Lead::SOURCE_MKT_BR], true))
                ? ($this->trucPageFacility()?->name)
                : null,
            // 2026-08-10: admin@longevity — cho phép chọn cơ sở tay vì không map cơ sở qua assignment.
            //   Dùng cho cả (a) Chia tự động khi tạo lead MKT, (b) Ghi nhận booking phase 3.
            'adminFacilityChoices' => $this->isAdminLongevity()
                ? \App\Models\PoolUnit::where('kind', 'facility')->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name'])
                : collect(),
            // 2026-08-05: preview sale kế trong MKT List — hiện trong banner "Tự động" để user theo dõi trước khi Lưu.
            'mktNextSale' => (! $this->lead?->exists && in_array($this->sourceGroup, [Lead::SOURCE_MKT, Lead::SOURCE_MKT_BR], true) && $this->mktMode === 'auto' && ($__f = $this->trucPageFacility()))
                ? $this->previewMktNextSale($__f->id)
                : null,
            'mktListToday' => (! $this->lead?->exists && in_array($this->sourceGroup, [Lead::SOURCE_MKT, Lead::SOURCE_MKT_BR], true) && ($__f2 = $this->trucPageFacility()))
                ? \App\Models\DailyAttendance::with('user')
                    ->where('facility_pool_unit_id', $__f2->id)
                    ->whereDate('work_date', now()->toDateString())
                    ->where('is_mkt', true)
                    ->orderBy('checkin_at')->get()
                : collect(),
            // 2026-08-05: user list cho radio "Thủ công" — filter theo data_scope của user hiện tại (visibleOrgUnitIds).
            //   Chỉ trả về khi user có perm lead.assign_direct. Giới hạn active + status active.
            'manualAssignableUsers' => (auth()->user()->hasPermission('lead.assign_direct')
                && in_array($this->sourceGroup, [Lead::SOURCE_MKT, Lead::SOURCE_MKT_BR], true) && ! $this->lead?->exists)
                ? \App\Models\User::whereHas('assignments', fn ($q) => $q->whereIn('org_unit_id', auth()->user()->visibleOrgUnitIds() ?: [0]))
                    ->where('status', \App\Models\User::STATUS_ACTIVE)
                    ->orderBy('name')->get()
                : collect(),
            // 2026-08-05: preview N sale kế tiếp trong UPS Sale list.
            //   Chọn cơ sở UPS = cơ sở của user thao tác (assignment → org_pool_map → PoolUnit facility).
            //   Sale ở HN → dùng cơ sở HN. Note: hiện chưa map scrm.facilities.id → pool_units.id
            //   (khác cây, cần bảng map riêng). Nếu sau này 1 sale thao tác nhiều cơ sở, add dropdown chọn.
            'cvPreview' => ($__cvPool = $this->trucPageFacility())
                ? $this->previewNextGreets($__cvPool->id, count($this->newBookingConsultantIds))
                : [],
            'cvPoolFacilityName' => $__cvPool?->name,
            'staffTree' => $staffTree,
            'allStaff' => $allStaff,
            'serviceTree' => $serviceTree = Service::whereNull('parent_id')->where('active', true)
                ->with(['children' => fn ($q) => $q->where('active', true)->orderBy('name')
                    ->with(['children' => fn ($q2) => $q2->where('active', true)->orderBy('name')])])
                ->orderBy('name')->get(),
            'svcTreeJson' => $serviceTree->map(function ($cat) {
                return [
                    'id' => $cat->id, 'name' => $cat->name, 'code' => $cat->code, 'is_cat' => true,
                    'children' => $cat->children->map(function ($child) {
                        $sub = $child->children;
                        if ($sub->isNotEmpty()) {
                            return [
                                'id' => $child->id, 'name' => $child->name, 'code' => $child->code, 'is_cat' => true,
                                'children' => $sub->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'is_cat' => false, 'children' => []])->values(),
                            ];
                        }
                        return ['id' => $child->id, 'name' => $child->name, 'code' => $child->code, 'is_cat' => false, 'children' => []];
                    })->values(),
                ];
            })->values()->toJson(),
            'assignedConsultants' => $assignedConsultants,
            'consultantUsers' => $consultantUsers,
        ];
    }
};
?>

<div x-data="{ extraConsultants: {{ $consultant3Id ? 2 : ($consultant2Id ? 1 : 0) }} }">
<?php
    $canDistribute = auth()->user()->hasPermission('lead.distribute');
    // Phase 6.20 — readonly mode cho user chỉ có lead.read_booking (Team booking).
    $canWrite = $lead
        ? $lead->canEditPersonalInfo(auth()->user())
        : auth()->user()->hasPermission('lead.create');
    // 2026-08-05: owner có canLogCall/canLogBooking → không phải "readonly" (họ ghi log được ở tab tương ứng).
    // Chỉ đúng nghĩa readonly khi cả 3 đều không.
    $ownerCanLog = $lead && ($lead->canLogCall(auth()->user()) || $lead->canLogBooking(auth()->user()));
    $isReadonly = ! $canWrite && ! $ownerCanLog;
    $isInfoReadonly = ! $canWrite; // info personal readonly nhưng vẫn ghi log được — dùng cho banner mới.
    $canBookAction = $lead && $lead->canBookAction(auth()->user());
    // 2026-08-05: Trực Page chỉ được điền custom fields phase 2 — khóa lịch sử call trở xuống
    //   (Phase 2 order-1/2/3 + Phase 3/4/5 panels đều trong fieldset cfLocked bên dưới).
    $isTrucPage = auth()->user()->hasRole('Trực Page');
    // 2026-08-09: Nút "Mở PM Booking" — ưu tiên deep-link đến booking cụ thể nếu đã có sbooking_booking_id.
    // Fallback: danh sách sbooking filter theo crm_khach_ma (thấy hết booking của khách + trạng thái + sale).
    // KHÔNG mở "tạo mới" (vô lý — Sale muốn xem lịch đã book chứ không phải tạo lại).
    $bookingClinicUrl = null;
    if ($canBookAction && $lead) {
        $_coSoSlug = $lead->resolvedBookingSlug();
        $_bookingBase = \App\Models\AppSetting::get('booking_url', config('services.booking.url'));
        if ($_coSoSlug && $_bookingBase) {
            $_base = rtrim($_bookingBase, '/') . '/' . $_coSoSlug;
            $_syncedBooking = $lead->bookingLogs()
                ->whereNotNull('sbooking_booking_id')
                ->orderByDesc('id')->first();
            if ($_syncedBooking) {
                $bookingClinicUrl = $_base . '/xem-dat-phong/' . $_syncedBooking->sbooking_booking_id;
            } elseif ($lead->code) {
                $bookingClinicUrl = $_base . '/danh-sach?' . http_build_query(['crm_khach_ma' => $lead->code]);
            }
        }
    }
?>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <div class="text-sm text-ink/50 mb-1">
                @if (auth()->user()->hasPermission('lead.view'))
                    <a href="{{ route('leads.index') }}" class="hover:text-gold-600">Khách hàng</a>
                    <span class="mx-1">›</span>
                @endif
                @if ($lead)
                    <a href="{{ route('leads.show', $lead) }}" class="hover:text-gold-600">Chi tiết khách hàng</a>
                    <span class="mx-1">›</span>
                    <span class="text-gold-700 font-medium">Cập nhật</span>
                @else
                    <span class="text-gold-700 font-medium">Thêm mới</span>
                @endif
            </div>
            <h1 class="text-3xl font-bold mb-1">{{ $lead ? 'Cập nhật - ' . ($lead->name ?: 'Khách hàng') : 'Thêm Mới Khách Hàng' }}</h1>
            @if ($lead)
                @php
                    $isBooking = $lead->pipeline_phase === \App\Models\Lead::PHASE_BOOKING;
                    $isWaiting = $lead->pipeline_status === \App\Models\Lead::PSTATUS_WAITING;
                    $badgeClass = $isBooking
                        ? ($isWaiting ? 'bg-amber-100 text-amber-800 border-amber-200' : 'bg-blue-100 text-blue-800 border-blue-200')
                        : ($isWaiting ? 'bg-purple-100 text-purple-800 border-purple-200' : 'bg-green-100 text-green-800 border-green-200');
                @endphp
                @if ($lead->code)
                    <div class="font-mono text-sm text-gold-700 mt-1">{{ $lead->code }}</div>
                @endif
                <span class="inline-block mt-2 text-xs font-semibold px-2.5 py-1 rounded-full border {{ $badgeClass }}"
                      title="Phase & trạng thái lifecycle">
                    {{ $lead->pipelineLabel() }}
                </span>
            @else
                <p class="text-sm text-ink/60">Vui lòng điền đầy đủ thông tin để cập nhật vào hệ thống sales pipeline.</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ $lead ? route('leads.show', $lead) : (auth()->user()->hasPermission('lead.view') ? route('leads.index') : route('dashboard')) }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
            {{-- 2026-08-09: đổi "Đặt booking" (chuyển tab nội bộ) → "Mở PM Booking" (mở tab mới sang sbooking).
                 Sbooking dùng session riêng, session SCRM không share — nếu user chưa login sbooking sẽ vào form login (username khớp SCRM sau refactor 2026-08-09). --}}
            @if ($canBookAction && $lead?->exists && $bookingClinicUrl)
                <a href="{{ $bookingClinicUrl }}" target="_blank" rel="noopener"
                   class="flex items-center gap-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 px-5 py-2.5 rounded-md"
                   title="Mở phần mềm Booking (sbooking) — tab mới. Đăng nhập username khớp SCRM.">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                    Mở PM Booking
                </a>
            @endif
            {{-- 2026-08-05: dùng $canWrite trực tiếp (canEditPersonalInfo) — không dùng $isReadonly (đã nới cho owner). Sale owner không sửa info → không show button (khỏi bấm ăn 403). --}}
            @if ($canWrite)
                <button wire:click="saveAndGoToBooking" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin</button>
            @endif
        </div>
    </div>

    @if ($isReadonly)
        <div class="mb-5 bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-700 flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
            <span>Bạn đang xem ở chế độ chỉ đọc — không có quyền chỉnh sửa thông tin ở phase này. @if ($canBookAction)Có thể bấm nút <strong>Mở PM Booking</strong> để chuyển sang phần mềm đặt lịch.@endif</span>
        </div>
    @elseif ($isInfoReadonly && $ownerCanLog)
        {{-- 2026-08-05: banner cho owner (Sale/Tele) — info thì readonly (do phase closure), nhưng ghi log call/booking ở tab tương ứng OK. --}}
        <div class="mb-5 bg-emerald-50 border border-emerald-300 rounded-lg px-4 py-3 text-sm text-emerald-900 flex items-start gap-2">
            <span class="text-lg">✅</span>
            <span>
                <b>Bạn là người phụ trách lead này.</b> Thông tin cá nhân (Tên/SĐT/Nguồn…) đã khoá do Phase 1 chốt, nhưng bạn <b>vẫn ghi được cuộc gọi ở tab "Gọi điện"</b>@if ($canBookAction) và <b>booking ở tab "Booking thăm khám"</b>@endif. Chuyển tab để thao tác.
            </span>
        </div>
    @endif

    {{-- 2026-08-03 bug #5: banner cho CV-only (chỉ được viết bình luận/phản hồi khách). --}}
    @if ($this->isCvOnly)
        <div class="mb-5 bg-amber-50 border border-amber-300 rounded-lg px-4 py-3 text-sm text-amber-800 flex items-center gap-2">
            <span>🔒</span>
            <span>Bạn được gán làm <strong>Chuyên viên tư vấn</strong> cho lead này — chỉ được viết bình luận/phản hồi khách. Các thao tác sửa thông tin, đặt booking, đổi phase… đã khoá. Liên hệ CM/Admin nếu cần thay đổi.</span>
        </div>
    @endif

    @if ($errors->has('phone') && $duplicateLeadId)
        <div class="mb-5 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
            SĐT đã tồn tại — <a href="{{ route('leads.show', $duplicateLeadId) }}" class="font-semibold underline">mở lead hiện có</a> để cập nhật thay vì tạo mới.
        </div>
    @endif

    {{-- Phase 6.21g — hiển thị tổng errors ở đầu form để user thấy dù đang ở tab nào --}}
    @if ($errors->any())
        <div x-data x-init="$nextTick(() => { $el.scrollIntoView({ behavior: 'smooth', block: 'center' }) })"
             class="mb-5 bg-red-50 border border-red-300 rounded-lg px-4 py-3 text-sm text-red-800">
            <div class="font-bold mb-1">⚠️ Không thể lưu — sửa các lỗi sau:</div>
            <ul class="list-disc pl-5 space-y-0.5 text-xs">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <fieldset {{ $isReadonly ? 'disabled' : '' }} class="min-w-0 border-0 p-0 m-0 {{ $isReadonly ? 'opacity-80' : '' }}">
    {{-- Phase 6.21d — layout tab-driven (đúng mockup): các card wrap theo x-show phase, chỉ 1 x-data outer --}}
    <div class="space-y-4" x-data="{
        phase: @entangle('activePhase').live,
        cfLocked: @js($phaseLocked ?? [])
    }">
        @php
            $cfPhases = \App\Models\Lead::CF_PHASE_LABELS;
            $cfStartPhase = $lead?->exists ? $lead->startPhase() : (($sourceGroup && isset(\App\Models\Lead::CF_START_PHASE_BY_SOURCE[$sourceGroup])) ? \App\Models\Lead::CF_START_PHASE_BY_SOURCE[$sourceGroup] : 1);
            $cfOpenFrom = $lead?->exists ? $lead->openFrom() : 1;
            $cfClosures = $lead?->exists ? $lead->phaseClosures->keyBy('phase') : collect();
        @endphp

        {{-- Header khách hàng — chia 50/50: trái = info + pipeline, phải = trao đổi (2 chiều SCRM ↔ Sbooking) --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="grid md:grid-cols-2 gap-5">
              {{-- Info khách + pipeline + trạng thái chăm sóc — layout dọc gọn --}}
              <div class="space-y-3">
                {{-- Row 1: KH code + Tên + SĐT + Cơ sở --}}
                <div>
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                        <span class="text-xs text-ink/50 font-mono">{{ $lead?->code ?? 'KH mới — chưa có mã' }}</span>
                        @if ($lead?->exists)
                            @if ($lead->is_first_visit)
                                <span class="text-[10px] px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-semibold uppercase">Đến lần đầu</span>
                            @else
                                <span class="text-[10px] px-2 py-0.5 rounded bg-purple-100 text-purple-700 font-semibold uppercase">Khách quay lại</span>
                            @endif
                        @endif
                        @if ($lead?->exists)
                            @if ($lead->isBulkOpen())
                                <span class="ml-auto inline-flex items-center gap-1 px-2.5 py-0.5 rounded bg-blue-50 border border-blue-200 text-blue-800 text-xs font-semibold">
                                    Đang nhập phase {{ $lead->openFrom() }}→{{ $lead->startPhase() }}
                                </span>
                            @else
                                {{-- 2026-08-09: badge phản ánh tab đang xem (activePhase) thay vì $lead->phase.
                                     Trước đây pill kẹt ở Phase 1 dù user đã click sang phase 3 → confusing. --}}
                                @php $__pillIdx = max((int) $lead->phase, (int) $activePhase); @endphp
                                <span class="ml-auto inline-flex items-center gap-1 px-2.5 py-0.5 rounded bg-blue-50 border border-blue-200 text-blue-800 text-xs font-semibold">
                                    Phase {{ $__pillIdx }} · {{ \App\Models\Lead::CF_PHASE_LABELS[$__pillIdx] ?? '?' }}
                                </span>
                            @endif
                        @endif
                    </div>
                    <h1 class="text-xl font-bold text-ink/90">{{ $name ?: '— Chưa có tên —' }}</h1>
                    <div class="text-sm text-ink/60 mt-1">
                        @if ($phone) SĐT: <b>{{ $phone }}</b> @endif
                        @if ($birthday) · Sinh: {{ \Carbon\Carbon::parse($birthday)->format('d/m/Y') }} @endif
                        @if ($lead?->orgUnit) · Cơ sở: {{ $lead->orgUnit->name }} @endif
                        @if ($sourceGroup) · Nguồn: <b>{{ \App\Models\Lead::SOURCE_GROUP_CODES[$sourceGroup] ?? $sourceGroup }}</b> @endif
                    </div>
                </div>

                @if ($lead?->exists)
                    @php
                        $inpUserId = $lead->imported_by ?: $lead->receiver_id;
                        $inpBy = $inpUserId ? \App\Models\User::find($inpUserId)?->name : null;
                        $teleUserId = $lead->phaseClosures->firstWhere('phase', 3)?->closed_by
                            ?? $lead->callLogs()->latest('called_at')->value('user_id');
                        if (! $teleUserId && ($lead->pipeline_phase === 'booking' || (int) $lead->phase === 3)) {
                            $teleUserId = $lead->owner_id;
                        }
                        // 2026-08-02: nguồn tự-up (BA/SA) — người tạo lead đóng luôn vai Tele.
                        //   - BA (Booking Appointment): Booker up + kiêm Tele.
                        //   - SA (Sale Appointment): Sale up + kiêm Tele + Sale.
                        if (! $teleUserId && in_array($lead->source_group, [\App\Models\Lead::SOURCE_BA, \App\Models\Lead::SOURCE_SA], true)) {
                            $teleUserId = $lead->imported_by ?: $lead->receiver_id;
                        }
                        $teleName = $teleUserId ? \App\Models\User::find($teleUserId)?->name : null;
                        // 2026-08-09: Sale phụ trách tư vấn = CV1 của booking mới nhất (theo priority):
                        //   1. booking_log.consultants position=1 (chuyên viên tư vấn được gán khi tạo booking)
                        //   2. booking_log.sale_id / consultant_1_id (fallback nếu chưa có consultants pivot)
                        //   3. phase 4 closed_by
                        //   4. owner nếu lead ở pipeline sale
                        $svUserId = null;
                        $__latestBooking = $lead->bookingLogs()->latest('id')->first();
                        if ($__latestBooking) {
                            $__cv1 = $__latestBooking->consultants()->wherePivot('position', 1)->first();
                            $svUserId = $__cv1?->id;
                        }
                        if (! $svUserId) {
                            $svUserId = $lead->phaseClosures->firstWhere('phase', 4)?->closed_by;
                        }
                        if (! $svUserId && ($lead->pipeline_phase === 'sale' || (int) $lead->phase >= 4)) {
                            $svUserId = $lead->owner_id;
                        }
                        // 2026-08-02: nguồn SA (Sale Appointment) — Sale up trực tiếp, đóng luôn vai Sale phụ trách.
                        if (! $svUserId && $lead->source_group === \App\Models\Lead::SOURCE_SA) {
                            $svUserId = $lead->imported_by ?: $lead->receiver_id;
                        }
                        $svName = $svUserId ? \App\Models\User::find($svUserId)?->name : null;
                    @endphp
                    {{-- Row 2: 3 cột người phụ trách gọn, chia đều --}}
                    <div class="grid grid-cols-3 gap-3 text-xs border-t border-gold-100 pt-2">
                        <div>
                            <div class="text-[10px] uppercase tracking-wide text-ink/50">Nhập lead</div>
                            <div class="font-semibold text-ink/80 truncate" title="{{ $inpBy }}">{{ $inpBy ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase tracking-wide text-ink/50">Phụ trách Tele</div>
                            <div class="font-semibold text-ink/80 truncate" title="{{ $teleName }}">{{ $teleName ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase tracking-wide text-ink/50">Phụ trách Tư vấn</div>
                            <div class="font-semibold text-ink/80 truncate" title="{{ $svName }}">{{ $svName ?? '—' }}</div>
                        </div>
                    </div>

                    {{-- Row 3: Ghi nhận tình trạng — full width, layout 2 cột (status 1&2 trái, tiền sử phải) --}}
                    <div class="border-t border-gold-100 pt-2">
                        <div class="text-[10px] uppercase tracking-wide font-semibold text-gold-700 mb-1.5">Ghi nhận tình trạng</div>
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <div class="space-y-0.5">
                                <div><span class="text-ink/50">Tình trạng lần 1:</span> <b class="text-ink/80">{{ $status_1 ?: '—' }}</b></div>
                                <div><span class="text-ink/50">Tình trạng lần 2:</span> <b class="text-ink/80">{{ $status_2 ?: '—' }}</b></div>
                            </div>
                            <div>
                                <div class="text-ink/50">Khai thác tiền sử:</div>
                                <div class="text-ink/80 italic whitespace-pre-wrap">{{ $medical_history ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200 text-slate-600 text-sm font-semibold">
                        Chưa tạo lead
                    </div>
                @endif
              </div>{{-- close left cell --}}

              {{-- CELL 2 (50%): Timeline trao đổi — merge booking_log.note (Data Source) + comments 2 chiều — Phase C1.f 2026-08-02 --}}
              <div>
                @if ($lead?->exists)
                    @php
                        $__commentPerPage = 3;
                        $__commentPage = max(1, (int) $this->commentPage);
                        // Nguồn 1: booking_logs.note (ghi chú lúc tạo booking bên scrm — "Data Source").
                        $__bookingNotes = $lead->bookingLogs()
                            ->whereNotNull('note')->where('note', '!=', '')
                            ->with('user:id,name')
                            ->get(['id', 'user_id', 'note', 'sbooking_booking_ma', 'sbooking_booking_id', 'scheduled_at', 'created_at'])
                            ->map(fn ($bl) => (object) [
                                'ts' => $bl->created_at,
                                'source' => 'scrm_note',
                                'user_name' => $bl->user?->name ?? 'System',
                                'content' => $bl->note,
                                'booking_ma' => $bl->sbooking_booking_ma,
                            ]);
                        // Nguồn 2: booking_log_comments (comment 2 chiều).
                        $__extraComments = \App\Models\BookingLogComment::where('lead_id', $lead->id)
                            ->with('bookingLog:id,sbooking_booking_ma,scheduled_at')
                            ->get()
                            ->map(fn ($c) => (object) [
                                'ts' => $c->created_at,
                                'source' => $c->source === 'sbooking' ? 'sbooking_comment' : 'scrm_comment',
                                'user_name' => $c->user_name,
                                'content' => $c->content,
                                'booking_ma' => $c->bookingLog?->sbooking_booking_ma,
                            ]);
                        // Merge + sort desc theo thời gian.
                        $__timeline = $__bookingNotes->concat($__extraComments)->sortByDesc('ts')->values();
                        $__commentTotal = $__timeline->count();
                        $__commentTotalPages = max(1, (int) ceil($__commentTotal / $__commentPerPage));
                        $__commentPage = min($__commentPage, $__commentTotalPages);
                        $__commentList = $__timeline->slice(($__commentPage - 1) * $__commentPerPage, $__commentPerPage);
                        $__bookingLogs = $lead->bookingLogs()->orderByDesc('created_at')->get(['id', 'sbooking_booking_ma', 'sbooking_booking_id', 'scheduled_at']);
                        // Metadata style per source.
                        $__srcMeta = [
                            'scrm_note' => ['label' => 'Hệ thống Data · Ghi chú booking', 'border' => 'border-amber-200', 'bg' => 'bg-amber-50', 'text' => 'text-amber-800', 'icon' => '📝'],
                            'scrm_comment' => ['label' => 'Hệ thống Data · Bình luận', 'border' => 'border-emerald-200', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-800', 'icon' => '💼'],
                            'sbooking_comment' => ['label' => 'Hệ thống Booking · Bình luận / phản ánh', 'border' => 'border-blue-200', 'bg' => 'bg-blue-50', 'text' => 'text-blue-800', 'icon' => '🏥'],
                        ];
                    @endphp
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-sm font-bold text-gold-700 flex items-center gap-1.5">💬 Trao đổi <span class="text-xs font-normal text-ink/40">({{ $__commentTotal }})</span></div>
                        <span class="text-[10px] text-ink/40">Gộp: ghi chú booking + bình luận Hệ thống Booking + trao đổi Hệ thống Data</span>
                    </div>
                    <div class="border border-gold-100 rounded-lg bg-slate-50/40 max-h-56 overflow-y-auto p-2 space-y-2 text-sm">
                        @forelse ($__commentList as $c)
                            @php $m = $__srcMeta[$c->source] ?? $__srcMeta['scrm_comment']; @endphp
                            <div class="border {{ $m['border'] }} {{ $m['bg'] }} rounded p-2">
                                <div class="flex items-center gap-1.5 text-[11px] mb-1 flex-wrap">
                                    <span class="font-semibold {{ $m['text'] }}">{{ $m['icon'] }} {{ $m['label'] }}</span>
                                    <span class="text-ink/40">·</span>
                                    <span class="text-ink/60">{{ $c->user_name }}</span>
                                    <span class="text-ink/40">·</span>
                                    <span class="text-ink/50">{{ $c->ts->diffForHumans() }}</span>
                                    @if ($c->booking_ma)
                                        <span class="ml-auto text-[10px] text-ink/40 font-mono">{{ $c->booking_ma }}</span>
                                    @endif
                                </div>
                                <div class="text-ink/80 whitespace-pre-wrap">{{ $c->content }}</div>
                            </div>
                        @empty
                            <p class="text-xs text-ink/40 italic text-center py-4">Chưa có ghi chú / bình luận nào.</p>
                        @endforelse
                    </div>
                    @if ($__commentTotalPages > 1)
                        <div class="flex items-center justify-between px-1 py-1 text-[11px]">
                            <button type="button" wire:click="$set('commentPage', {{ max(1, $__commentPage - 1) }})"
                                    @disabled($__commentPage <= 1)
                                    class="text-ink/60 hover:text-gold-700 disabled:opacity-30">‹ Trước</button>
                            <span class="text-ink/50">Trang {{ $__commentPage }}/{{ $__commentTotalPages }}</span>
                            <button type="button" wire:click="$set('commentPage', {{ min($__commentTotalPages, $__commentPage + 1) }})"
                                    @disabled($__commentPage >= $__commentTotalPages)
                                    class="text-ink/60 hover:text-gold-700 disabled:opacity-30">Sau ›</button>
                        </div>
                    @endif
                    @if ($__bookingLogs->isNotEmpty())
                        <div class="mt-2 space-y-1.5">
                            <div class="flex gap-1.5">
                                <select wire:model="newCommentBookingLogId" class="flex-1 border border-slate-300 rounded px-2 py-1 text-xs">
                                    <option value="">— Booking mới nhất —</option>
                                    @foreach ($__bookingLogs as $__bl)
                                        <option value="{{ $__bl->id }}">
                                            {{ $__bl->sbooking_booking_ma ?: '#' . $__bl->id }} · {{ $__bl->scheduled_at?->format('d/m H:i') ?: 'chưa đặt' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex gap-1.5">
                                <textarea wire:model="newCommentContent" rows="2" placeholder="Nhập nội dung trao đổi..."
                                          class="flex-1 border border-slate-300 rounded px-2 py-1 text-sm focus:outline-none focus:border-blue-500"></textarea>
                                <button type="button" wire:click="addComment"
                                        class="text-xs bg-blue-600 hover:bg-blue-700 text-white font-semibold px-3 rounded self-stretch">
                                    💬 Gửi
                                </button>
                            </div>
                        </div>
                    @else
                        <p class="text-xs text-ink/40 italic mt-2">Chưa có booking nào — tạo booking ở tab Booking để bắt đầu trao đổi.</p>
                    @endif
                @else
                    <p class="text-xs text-ink/40 italic">Lưu thông tin lead trước để mở khung trao đổi.</p>
                @endif
              </div>
            </div>
        </div>

        {{-- Arrow-breadcrumb 7 phase — sizing lớn theo mockup (Phase 6.21e) --}}
        <div class="bg-white border border-gold-200 rounded-xl shadow-card p-5">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <div class="text-sm uppercase tracking-wide text-gold-700 font-bold">Customer Flow — 6 phase</div>
                <div class="flex items-center gap-3 text-xs text-ink/60 flex-wrap">
                    <span class="inline-flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded bg-emerald-500"></span>Đã chốt</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded bg-blue-400"></span>Cần điền thông tin</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded bg-slate-200 border border-slate-300"></span>Chưa tới</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded bg-slate-300 opacity-60"></span>Chưa build</span>
                </div>
            </div>
            @if (session('cf_ok'))<div class="mb-3 px-4 py-2 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded">✓ {{ session('cf_ok') }}</div>@endif
            @if (session('cf_warn'))<div class="mb-3 px-4 py-2 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded">⚠ {{ session('cf_warn') }}</div>@endif
            @if (session('cf_error'))<div class="mb-3 px-4 py-2 bg-red-50 border border-red-200 text-red-800 text-sm rounded">✗ {{ session('cf_error') }}</div>@endif
            <div class="flex gap-1 overflow-x-auto pb-1">
                @foreach ($cfPhases as $idx => $label)
                    @php
                        $state = (in_array($idx, [6, 7], true))
    ? 'notbuilt'
    : ($cfClosures->has($idx)
        ? 'done'
        : (($idx <= $cfStartPhase || $idx == ($lead?->phase ?? 0)) ? 'open' : 'pending'));
                        $bg = match ($state) {
                            'done' => 'bg-emerald-500 text-white',
                            'current' => 'bg-blue-400 text-white',
                            'open' => 'bg-blue-400 text-white',
                            'pending' => 'bg-slate-200 text-slate-600',
                            'skipped' => 'bg-slate-100 text-slate-400 line-through cursor-not-allowed',
                            'notbuilt' => 'bg-slate-300 text-slate-500 opacity-60 cursor-not-allowed',
                        };
                        $disabled = in_array($state, ['skipped'], true);
                        $clip = $idx === 1 ? 'polygon(0 0,calc(100% - 14px) 0,100% 50%,calc(100% - 14px) 100%,0 100%)' : ($idx === 7 ? 'polygon(0 0,100% 0,100% 100%,0 100%,14px 50%)' : 'polygon(0 0,calc(100% - 14px) 0,100% 50%,calc(100% - 14px) 100%,0 100%,14px 50%)');
                    @endphp
                    <button type="button"
                            @if (! $disabled) wire:click="selectPhaseTab({{ $idx }})" @endif
                            :class="phase === {{ $idx }} ? 'ring-2 ring-offset-1 ring-indigo-500' : ''"
                            @if ($disabled) disabled @endif
                            style="clip-path: {{ $clip }};"
                            class="flex-1 min-w-[150px] px-5 py-3 text-left transition {{ $bg }}">
                        <div class="text-[10px] uppercase opacity-80 leading-none tracking-wide">Phase {{ $idx }}</div>
                        <div class="text-sm font-semibold mt-1 leading-tight">{{ $label }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Tabbar horizontal — chung màu vàng, active gạch chân --}}
        <div class="flex flex-wrap items-center border-b border-gold-200 bg-white rounded-t-lg">
            @foreach ($cfPhases as $idx => $label)
                @php $state = (in_array($idx, [6, 7], true))
    ? 'notbuilt'
    : ($cfClosures->has($idx)
        ? 'done'
        : (($idx <= $cfStartPhase || $idx == ($lead?->phase ?? 0)) ? 'open' : 'pending')); @endphp
                <button type="button" wire:click="selectPhaseTab({{ $idx }})"
                        :class="phase === {{ $idx }} ? 'border-b-2 border-gold-700 font-bold' : 'border-b-2 border-transparent font-medium'"
                        class="px-5 py-3 -mb-px text-base text-gold-700 whitespace-nowrap transition-colors hover:font-bold">
                    {{ $idx }}. {{ $label }}
                    @if ($state === 'done')<span class="text-emerald-500 ml-1">✓</span>@endif
                    @if ($state === 'notbuilt')<span class="text-[11px] text-gold-500 ml-1">(chưa build)</span>@endif
                </button>
            @endforeach
        </div>

        {{-- Info khách + custom fields — Phase 1 (Thêm mới lead) --}}
        <div class="space-y-3" x-show="phase === 1" x-cloak>
            @if ($phaseLocked[1] ?? false)
                <div class="px-4 py-2.5 bg-slate-100 border border-slate-300 rounded-lg text-sm text-slate-700 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                    <span>Phase 1 đã chốt, liên hệ quản lý nếu cần chỉnh sửa.</span>
                </div>
            @endif
            <fieldset @if ($phaseLocked[1] ?? false) disabled class="opacity-70 space-y-6 border-0 p-0 m-0" @else class="space-y-6 border-0 p-0 m-0" @endif>
            {{-- Thông tin khách hàng --}}
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2 flex-wrap">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                    Thông tin khách hàng
                    @if ($lead?->exists && $lead->pipeline_phase)
                        <span class="ml-auto text-xs font-semibold px-2.5 py-1 rounded-full border
                            @if ($lead->pipeline_phase === \App\Models\Lead::PHASE_BOOKING) bg-blue-50 border-blue-200 text-blue-700
                            @else bg-gold-50 border-gold-200 text-gold-700 @endif"
                            title="Trạng thái pipeline hiện tại của lead">
                            {{ $lead->pipelineLabel() }}
                        </span>
                    @endif
                </h2>
                <div class="space-y-4">
                    {{-- Phase 6.21c — in ngang 4 cột (style AMIS) --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Tên khách hàng <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="name" placeholder="Nhập họ và tên" class="w-full border rounded-md px-3 py-2.5 text-sm focus:outline-none {{ $errors->has('name') ? 'border-red-400 bg-red-50 focus:border-red-500' : 'border-gold-200 focus:border-gold-500' }}">
                            @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @if ($lead?->exists && ($lead->imported_by || $lead->receiver_id))
                                @php
                                    $_creator = \App\Models\User::find($lead->imported_by ?: $lead->receiver_id);
                                    $_createdAt = optional($lead->created_at)->format('d/m/Y H:i');
                                @endphp
                                <p class="text-[11px] text-ink/60 mt-1.5">
                                    Người tạo lead: <b class="text-ink/85">{{ $_creator?->name ?? '—' }}</b>
                                    @if ($_createdAt) · <span class="font-mono">{{ $_createdAt }}</span> @endif
                                </p>
                            @endif
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">SĐT <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="phone" placeholder="0xxx xxx xxx"
                                x-on:paste.stop="setTimeout(() => { let v = $event.target.value.replace(/\D+/g,''); if (v.startsWith('84') && v.length === 11) v = '0' + v.slice(2); if (v.length === 9 && !v.startsWith('0')) v = '0' + v; $event.target.value = v; $wire.set('phone', v); }, 0)"
                                x-on:blur="let v = $event.target.value.replace(/\D+/g,''); if (v.startsWith('84') && v.length === 11) v = '0' + v.slice(2); if (v.length === 9 && !v.startsWith('0')) v = '0' + v; if (v !== $event.target.value) { $event.target.value = v; $wire.set('phone', v); }"
                                class="w-full border rounded-md px-3 py-2.5 text-sm font-mono focus:outline-none {{ $errors->has('phone') ? 'border-red-400 bg-red-50 focus:border-red-500' : 'border-gold-200 focus:border-gold-500' }}">
                            @error('phone')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Ngày thu thập <span class="text-red-500">*</span></label>
                            <x-date-input field="received_date" />
                            @error('received_date')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Nhóm nguồn <span class="text-red-500">*</span></label>
                            @php
                                $_allowedSources = \App\Models\Lead::allowedSourceGroupsFor(auth()->user());
                                $_allSources = \App\Models\Lead::SOURCE_GROUPS;
                                $_sourceCodes = \App\Models\Lead::SOURCE_GROUP_CODES;
                            @endphp
                            <select wire:model.live="sourceGroup" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— Chọn nhóm nguồn —</option>
                                @foreach ($_allSources as $key => $label)
                                    @php
                                        $_code = $_sourceCodes[$key] ?? null;
                                        $_disabled = ! isset($_allowedSources[$key]);
                                        $_text = $label . ($_disabled ? ' - (không có quyền)' : '');
                                        $_title = $_disabled ? 'Bạn không có quyền up nguồn này' : '';
                                    @endphp
                                    <option value="{{ $key }}" {{ $_disabled ? 'disabled' : '' }} title="{{ $_title }}">{{ $_text }}</option>
                                @endforeach
                            </select>
                            @error('sourceGroup')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                            @php
                                $nextStep = null;
                                if (in_array($sourceGroup, [\App\Models\Lead::SOURCE_BOD, \App\Models\Lead::SOURCE_SA, \App\Models\Lead::SOURCE_BA], true)) {
                                    $nextStep = ! auth()->user()->hasPermission('lead.distribute')
                                        ? 'Lead sẽ tự động chia cho BẠN (' . auth()->user()->name . '). Không qua duyệt — nhập xong là xong.'
                                        : 'Chuyển sang tab "2. Chia số" để chọn sale nhận (bắt buộc, không qua duyệt).';
                                } elseif ($sourceGroup === \App\Models\Lead::SOURCE_WI) {
                                    $nextStep = 'Lead sẽ về kho team → chờ CM team sale chia cho nhân viên.';
                                } elseif (in_array($sourceGroup, [\App\Models\Lead::SOURCE_MKT, \App\Models\Lead::SOURCE_MKT_BR], true)) {
                                    $nextStep = 'Hệ thống tự chia Tele sale theo MKT List UPS hôm nay.';
                                } elseif ($sourceGroup === \App\Models\Lead::SOURCE_BDM) {
                                    $nextStep = 'Lead vào kho Booking, chờ CM chia cho Tele sale.';
                                }
                            @endphp
                            @if ($nextStep)
                                <div class="mt-2 flex items-start gap-2 px-3 py-2 bg-amber-50 border-l-4 border-amber-400 rounded text-sm text-amber-900">
                                    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span><b class="uppercase tracking-wide text-xs">Bước tiếp theo:</b> {{ $nextStep }}</span>
                                </div>
                            @endif
                            {{-- 2026-08-05: radio "Cách chia" moved xuống panel "Chia lead vào kho / cho sale" dưới. --}}
                        </div>
                    </div>
                    {{-- PAGE + Camp + Nguồn QC giờ là custom field phòng Marketing (Trường bổ sung); Link move sang tab Insight --}}
                    <div>
                        <label class="block text-sm font-medium mb-1.5">NOTE</label>
                        <textarea wire:model="note" rows="2" placeholder="Ghi chú thêm..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                </div>
            </div>

            {{-- Trường bổ sung — MOVED sang phase 3 (2026-08-02) — xem block bên dưới sau phase-1 wrapper. --}}
            @if (false)
            <div class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-1 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Trường bổ sung
                    <span class="text-xs font-normal text-ink/50">({{ $lead?->orgUnit?->name ?? $this->targetOrgUnit()?->name ?? 'mức công ty' }})</span>
                </h2>
                <p class="text-xs text-ink/50 mb-5">Trường có <span class="text-red-500">*</span> là bắt buộc.</p>
<?php $cfLabels = \App\Models\CustomField::labelMap($customFields); ?>
                <div class="space-y-4">
                    @foreach ($customFields as $field)
<?php $ck = $field->rules['code_kind'] ?? null; ?>
                        @continue($field->field_type === 'code' && $ck === 'fixed')
                        <div wire:key="cf-{{ $field->id }}">
                            <label class="block text-sm font-medium mb-1.5">
                                {{ $cfLabels[$field->id] ?? $field->label }}
                                @if ($field->required)<span class="text-red-500">*</span>@endif
                                @if ($field->affects_code)<span class="text-[10px] text-gold-700 ml-1">#mã KH</span>@endif
                                @if ($field->org_unit_id === null)
                                    <span class="text-[10px] uppercase tracking-wider text-ink/40 border border-gold-100 rounded px-1.5 py-0.5 ml-1">Công ty</span>
                                @endif
                            </label>
                            @if ($field->field_type === 'select' || ($field->field_type === 'code' && $ck === 'select'))
                                <select wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                    <option value="">— chọn —</option>
                                    @foreach ($field->options ?? [] as $option)
<?php $ol = $field->optionLabel($option); ?>
                                        <option value="{{ $option }}">{{ ($ol !== '' && $ol !== $option) ? "$ol ($option)" : $option }}</option>
                                    @endforeach
                                </select>
                            @elseif ($field->field_type === 'tick')
                                <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
                                    <input type="checkbox" wire:model="custom.{{ $field->id }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                                    Có
                                </label>
                            @elseif ($field->field_type === 'date')
                                <x-date-input field="custom.{{ $field->id }}" />
                            @elseif ($field->field_type === 'number')
                                <input type="number" step="any" wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            @elseif ($field->field_type === 'email')
                                <input type="email" wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            @else
                                <input type="text" wire:model="custom.{{ $field->id }}" @if($field->field_type==='code') style="text-transform:uppercase" @endif class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                            @endif
                            @error('custom.' . $field->id)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- INSIGHT chuyển sang cột phải (tab) Phase 6.15 --}}
            </fieldset>
        </div>

        {{-- Trường bổ sung — hiển thị ở phase 1 (Tài khoản nhập lead điền một lượt) — 2026-08-08 --}}
        @if ($customFields->isNotEmpty())
        <div x-show="phase === 1" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
            <h2 class="font-bold text-gold-700 mb-1 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Trường bổ sung
                <span class="text-xs font-normal text-ink/50">({{ $lead?->orgUnit?->name ?? $this->targetOrgUnit()?->name ?? 'mức công ty' }})</span>
            </h2>
            <p class="text-xs text-ink/50 mb-5">Trường có <span class="text-red-500">*</span> là bắt buộc.</p>
<?php $cfLabels2 = \App\Models\CustomField::labelMap($customFields); ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-4">
                @foreach ($customFields as $field)
<?php $ck2 = $field->rules['code_kind'] ?? null; ?>
                    @continue($field->field_type === 'code' && $ck2 === 'fixed')
                    <div wire:key="cf-p3-{{ $field->id }}">
                        <label class="block text-sm font-medium mb-1.5">
                            {{ $cfLabels2[$field->id] ?? $field->label }}
                            @if ($field->required)<span class="text-red-500">*</span>@endif
                            @if ($field->affects_code)<span class="text-[10px] text-gold-700 ml-1">#mã KH</span>@endif
                            @if ($field->org_unit_id === null)
                                <span class="text-[10px] uppercase tracking-wider text-ink/40 border border-gold-100 rounded px-1.5 py-0.5 ml-1">Công ty</span>
                            @endif
                        </label>
                        @if ($field->field_type === 'select' || ($field->field_type === 'code' && $ck2 === 'select'))
                            <select wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                                <option value="">— chọn —</option>
                                @foreach ($field->options ?? [] as $option)
<?php $ol2 = $field->optionLabel($option); ?>
                                    <option value="{{ $option }}">{{ ($ol2 !== '' && $ol2 !== $option) ? "$ol2 ($option)" : $option }}</option>
                                @endforeach
                            </select>
                        @elseif ($field->field_type === 'tick')
                            <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
                                <input type="checkbox" wire:model="custom.{{ $field->id }}" class="rounded border-gold-300 text-gold-600 focus:ring-gold-500 w-5 h-5">
                                Có
                            </label>
                        @elseif ($field->field_type === 'date')
                            <x-date-input field="custom.{{ $field->id }}" />
                        @elseif ($field->field_type === 'number')
                            <input type="number" step="any" wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @elseif ($field->field_type === 'email')
                            <input type="email" wire:model="custom.{{ $field->id }}" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @else
                            <input type="text" wire:model="custom.{{ $field->id }}" @if($field->field_type==='code') style="text-transform:uppercase" @endif class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        @endif
                        @error('custom.' . $field->id)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Content các tab-phase khác (2, 3, 4, 5, 6, 7) — nằm cùng x-data outer --}}
<?php $staffTreeJson = json_encode($staffTree, JSON_UNESCAPED_UNICODE); ?>
<?php $consultantUsersJson = json_encode($consultantUsers, JSON_UNESCAPED_UNICODE); ?>
        <script>
            window.__staffTree = {!! $staffTreeJson !!};
            window.__consultantUsers = {!! $consultantUsersJson !!};
        </script>
        {{-- Phase locked banner (reactive theo tab hiện tại) --}}
        <template x-if="cfLocked[phase]">
            <div class="px-4 py-2.5 bg-slate-100 border border-slate-300 rounded-lg text-sm text-slate-700 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                <span>Phase <span x-text="phase"></span> đã chốt, liên hệ quản lý nếu cần chỉnh sửa.</span>
            </div>
        </template>
        {{-- 2026-08-05: Trực Page — disable fieldset từ Phase 2 trở đi (call/booking/consult/upsell).
             2026-08-07: MỞ Phase 1 (Chia số) — Trực Page cần chọn Tự động / Chia về kho khi up lead MKT.
             Custom fields phase 2 nằm NGOÀI fieldset này (line ~2481) nên vẫn mở. --}}
        <fieldset :disabled="!!cfLocked[phase] || (@js($isTrucPage) && phase !== 1)" x-bind:class="cfLocked[phase] || (@js($isTrucPage) && phase !== 1) ? 'opacity-70 flex flex-col space-y-4 border-0 p-0 m-0' : 'flex flex-col space-y-4 border-0 p-0 m-0'">
            @if ($isTrucPage)
                <div x-show="phase !== 1" class="px-4 py-2.5 bg-amber-50 border border-amber-300 rounded-lg text-sm text-amber-900 flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                    <span>Tài khoản <b>Trực Page</b> — chỉ nhập được ở tab <b>Tài khoản nhập lead</b> (gồm cả "Trường bổ sung"). Lịch sử cuộc gọi, phân bổ CV, booking, check-in đều <b>chỉ đọc</b>.</span>
                </div>
            @endif

            {{-- Arrow-breadcrumb + tabbar đã move lên đầu (dưới cùng x-data). Các phase blocks bên dưới đây. --}}

            {{-- ============= Phase 6.21i — Panel "Phân bổ nhân viên tư vấn" (Phase 4) — format giống Phase 2 ============= --}}
            @php
                $canAssignSale = auth()->user()->hasPermission('lead.distribute_sale')
                    || auth()->user()->hasPermission('phase.rollback')
                    || auth()->user()->hasPermission('lead.distribute');
                // Owner khi lead ở phase Booking = tele đang xử lý; khi phase Sale = sale.
                // Fix 2026-08-01: hiển thị đúng theo pipeline_phase, không quy tất cả về "sale".
                $inBookingPhase = $lead?->pipeline_phase === \App\Models\Lead::PHASE_BOOKING;
                $currentTele = $inBookingPhase ? $lead?->owner : null;
                $currentSale = $inBookingPhase ? null : $lead?->owner;
                // Fallback lịch sử: nếu phase 3 đã đóng nhưng owner đã sang sale → đọc closer.
                if (! $currentTele && $lead) {
                    $teleClosedBy = $lead->phaseClosures->firstWhere('phase', 3)?->closed_by;
                    $currentTele = $teleClosedBy ? \App\Models\User::find($teleClosedBy) : null;
                }
                // Team đang giữ: khi phase Booking, dùng team Tele mà tele viên thuộc về;
                // khi phase Sale, dùng org_unit_id hiện tại của lead.
                $holdingTeamName = $inBookingPhase
                    ? ($currentTele?->assignments->first()?->orgUnit?->name ?? $lead?->orgUnit?->name)
                    : ($lead?->orgUnit?->name);
            @endphp
            <div x-show="phase === 3" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                    Phân bổ nhân viên tư vấn
                </h2>

                {{-- Info readonly 4 cột (format giống Phase 2) --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm mb-4">
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Nguồn khách</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ \App\Models\Lead::SOURCE_GROUPS[$sourceGroup] ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Team đang giữ</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ $holdingTeamName ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Tele đã xử lý</label>
                        @if ($currentTele)
                            <div class="relative" x-data="{ show: false }">
                                <div @click="show = !show" class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50 cursor-pointer hover:bg-gold-50 transition-colors">{{ $currentTele->name }}</div>
                                <div x-show="show" x-cloak @click.outside="show = false" class="absolute z-10 mt-1 left-0 bg-white border border-gold-200 rounded-lg shadow-lg px-3 py-2 text-sm">
                                    <div class="text-[11px] text-ink/50 mb-0.5">SĐT nhân viên</div>
                                    @if ($currentTele->phone)
                                        <a href="tel:{{ $currentTele->phone }}" class="font-mono font-semibold text-gold-700 hover:underline">{{ $currentTele->phone }}</a>
                                    @else
                                        <span class="text-ink/40 italic">Chưa có SĐT</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50 text-ink/50 italic">—</div>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Sale phụ trách</label>
                        @if ($currentSale)
                            <div class="relative" x-data="{ show: false }">
                                <div @click="show = !show" class="px-3 py-2 border border-gold-200 rounded-md bg-emerald-50 border-emerald-200 text-emerald-800 font-semibold cursor-pointer hover:bg-emerald-100 transition-colors">{{ $currentSale->name }}</div>
                                <div x-show="show" x-cloak @click.outside="show = false" class="absolute z-10 mt-1 left-0 bg-white border border-gold-200 rounded-lg shadow-lg px-3 py-2 text-sm">
                                    <div class="text-[11px] text-ink/50 mb-0.5">SĐT nhân viên</div>
                                    @if ($currentSale->phone)
                                        <a href="tel:{{ $currentSale->phone }}" class="font-mono font-semibold text-gold-700 hover:underline">{{ $currentSale->phone }}</a>
                                    @else
                                        <span class="text-ink/40 italic">Chưa có SĐT</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50 text-ink/50 italic">— chưa phân —</div>
                        @endif
                    </div>
                </div>

                {{-- Phase 4 rework 2026-08-01: Sale phụ trách = CV1 của booking mới nhất được duyệt.
                     Chọn CV ngay trong khung "Tạo booking" (Phase 4). --}}
                <div class="border-t border-gold-200 pt-4 text-xs text-ink/50 italic">
                    Chuyên viên tư vấn giờ chọn theo <b>từng lần booking</b> ở Phase 4. CV1 của booking được duyệt sẽ tự thành Sale phụ trách lead.
                </div>
            </div>

            {{-- ============= Phase 4 rework 2026-08-01 — GHI NHẬN BOOKING (log nội bộ) ============= --}}
            {{-- 2026-08-04 (Task 2): chỉ Admin + Admin cơ sở (perm lead.book_action) mới thấy khung tạo booking. --}}
            @if (auth()->user()?->hasPermission('lead.book_action'))
            <div x-show="phase === 3" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-2 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Ghi nhận booking <span class="text-sm text-ink/50 font-normal">(log nội bộ — tracking bên CRM)</span>
                </h2>
                <div class="mb-4 p-2.5 bg-blue-50 border border-blue-200 rounded text-xs text-blue-900 leading-relaxed">
                    ℹ️ Bấm <b>Thêm booking</b> → tạo record bên lara-sbooking ở trạng thái <b>Chờ duyệt</b>. Admin sbooking duyệt xong → tự cập nhật trạng thái <b>Đã duyệt</b> về đây.
                    <b>Chưa map cơ sở sang sbooking</b> (Thiết lập → Kết nối Booking) → <b>không cho ghi</b>.
                </div>

                {{-- TRẠNG THÁI ĐẶT LỊCH (tổng thể) — readonly, tự sync từ booking_logs mới nhất --}}
                <div class="mb-4 pb-4 border-b border-slate-200">
                    <label class="block text-sm font-medium mb-1.5">TRẠNG THÁI ĐẶT LỊCH (tổng thể)</label>
                    <div class="inline-flex items-center gap-2 px-3 py-2 border border-gold-200 rounded-md bg-slate-50 text-sm min-w-[240px]">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <span class="font-semibold text-ink/80">{{ \App\Models\Lead::BOOKING_STATUSES[$bookingStatus] ?? $bookingStatus ?: 'Chưa đặt' }}</span>
                        <span class="text-xs text-ink/40 ml-auto">🔒 tự sync</span>
                    </div>
                    <p class="text-xs text-ink/50 mt-1">Tự cập nhật khi thêm booking mới hoặc bên hệ thống booking sync về — không chỉnh tay.</p>
                </div>

                @if ($lead?->exists)
                    {{-- 2026-08-05: bỏ nút "Đồng bộ từ bên booking" (dùng Reverb push tự sync), thay bằng "Check UPS"
                         cho user xem list sale hôm nay trước khi bấm Thêm booking. Popup Alpine, không nhảy trang. --}}
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[11px] text-ink/50">💡 Preview CV auto-refresh mỗi 5s — sale kế tiếp trong UPS list sẽ cập nhật khi có người chiếm/thả.</span>
                        <div x-data="{ show: false }" class="relative">
                            <button type="button" @click="show = !show"
                                    class="text-xs font-bold px-3 py-1.5 rounded whitespace-nowrap inline-flex items-center gap-1.5 bg-white border border-gold-300 text-gold-700 hover:bg-gold-50">
                                ⚡ Check UPS List
                            </button>
                            <div x-show="show" @click.outside="show = false" x-transition x-cloak
                                 class="absolute right-0 mt-2 w-[calc(100vw-2rem)] sm:w-96 max-w-md bg-white border border-gold-200 rounded-lg shadow-xl z-30 max-h-[500px] overflow-auto">
                                @php
                                    $__upsAtts = $cvPoolFacilityName ? \App\Models\DailyAttendance::with('user')
                                        ->where('facility_pool_unit_id', ($this->trucPageFacility()?->id ?? 0))
                                        ->whereDate('work_date', now()->toDateString())
                                        ->whereIn('list_bucket', \App\Services\Ups\UpsDispatcher::BUCKET_ORDER_GREET)
                                        ->orderByRaw("FIELD(list_bucket, 'A','B','C','OFF')")
                                        ->orderBy('checkin_at')->get() : collect();
                                @endphp
                                <div class="px-3 py-2 border-b border-gold-100 bg-gold-50 sticky top-0">
                                    <div class="text-xs font-bold text-gold-800">UPS Sale hôm nay · {{ $cvPoolFacilityName ?? '—' }}</div>
                                    <div class="text-[10px] text-gold-700">{{ $__upsAtts->count() }} sale · Auto-refresh 5s</div>
                                </div>
                                @if ($__upsAtts->isEmpty())
                                    <div class="px-3 py-6 text-center text-xs text-red-600">⚠️ Chưa có UPS list Sale — không tạo được booking. Liên hệ Admin BO.</div>
                                @else
                                    <ul class="divide-y divide-gold-50">
                                        @foreach ($__upsAtts as $__a)
                                            @php
                                                $__busy = (bool) $__a->is_busy;
                                                $__off  = (bool) $__a->is_off;
                                                $__pause = (bool) ($__a->dung_nhan_lead ?? false);
                                                $__label = $__off ? 'Offlist' : ($__pause ? '⏸️ Dừng nhận lead' : ($__busy ? '🔴 Đang tiếp đón' : '🟢 Sẵn sàng'));
                                                $__cls   = $__off ? 'bg-rose-100 text-rose-700' : ($__pause ? 'bg-slate-200 text-slate-700' : ($__busy ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'));
                                                $__bkc   = match ($__a->list_bucket) { 'A'=>'bg-blue-100 text-blue-800', 'B'=>'bg-teal-100 text-teal-800', 'C'=>'bg-slate-200 text-slate-800', 'OFF'=>'bg-rose-100 text-rose-800', default=>'bg-gold-100 text-gold-800' };
                                                $__isNext = ! empty($cvPreview) && $cvPreview[0]?->id === $__a->user_id;
                                            @endphp
                                            <li class="px-3 py-2 flex items-center gap-2 hover:bg-gold-50/40 {{ $__isNext ? 'bg-emerald-50 ring-1 ring-emerald-400' : '' }}">
                                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded {{ $__bkc }}">{{ $__a->list_bucket }}</span>
                                                <div class="flex-1 min-w-0">
                                                    <div class="text-sm font-semibold text-ink truncate">
                                                        @if ($__isNext) <span class="text-emerald-700">➤</span> @endif
                                                        {{ $__a->user?->name ?? 'Sale #'.$__a->user_id }}
                                                    </div>
                                                    <div class="text-[11px] text-ink/50">Check-in {{ optional($__a->checkin_at)?->setTimezone('Asia/Ho_Chi_Minh')->format('H:i') }}</div>
                                                </div>
                                                <span class="text-[11px] font-semibold px-2 py-0.5 rounded {{ $__cls }} whitespace-nowrap">{{ $__label }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div wire:poll.5s class="border border-dashed border-slate-300 bg-slate-50 rounded p-3 space-y-2">
                        <div class="text-xs font-semibold text-ink/60">Thêm booking mới <span class="font-normal text-ink/40">— mặc định "Chờ xác nhận", bên booking cập nhật sẽ tự sync về đây</span></div>
                        {{-- 2026-08-11: bỏ dropdown chọn cơ sở UPS tay — admin chọn trên navbar (AdminScope) là đủ. --}}
                        {{-- Hàng 1: Loại | Trạng thái (lock) | Datetime --}}
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                            {{-- 2026-08-09: 3 bucket — Khám lâm sàng / Tư vấn / Dịch vụ. --}}
                            <select wire:model.live="newBookingType" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                <option value="">— Loại * —</option>
                                <option value="kham_ls">🩺 Khám lâm sàng</option>
                                <option value="tu_van">💬 Tư vấn</option>
                                <option value="dich_vu">💆 Dịch vụ</option>
                            </select>
                            <div class="inline-flex items-center gap-1.5 border border-slate-300 rounded px-2 py-1.5 text-sm bg-slate-100 text-ink/60">
                                <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                                Chờ xác nhận
                                <span class="ml-auto text-[10px]">🔒</span>
                            </div>
                            {{-- Phase C1.d rev2: Row 1 giữ Ngày (date only). Giờ chuyển xuống Row 3 sau khi có (phòng + dịch vụ). --}}
                            <input type="date" wire:model.live="newBookingDate"
                                   class="border border-slate-300 rounded px-2 py-1.5 text-sm"
                                   title="Chọn ngày">
                        </div>
                        {{-- 2026-08-09 relayout: 3 cột — Cột 1 Địa điểm (Cơ sở/Phòng/BS) | Cột 2 Nội dung (Dịch vụ/Số lượng) | Cột 3 Thời gian (Khung giờ).
                             Thứ tự tương tác vẫn theo yêu cầu sbooking: user chọn cơ sở → phòng (cột 1) → dịch vụ (cột 2) → giờ (cột 3). --}}
                        @php
                            // Resolve sbCoSoForBs từ facility (dùng cho cả BS list + service list).
                            $sbCoSoForBs = null;
                            if ($newBookingFacilityId) {
                                $__f = \App\Models\Facility::find($newBookingFacilityId);
                                while ($__f) {
                                    if ($__f->sbooking_co_so_id) { $sbCoSoForBs = (int) $__f->sbooking_co_so_id; break; }
                                    $__f = $__f->parent_id ? \App\Models\Facility::find($__f->parent_id) : null;
                                }
                            }
                            $bsList = $sbCoSoForBs
                                ? \App\Models\SbBacSi::where(function ($q) use ($sbCoSoForBs) {
                                        $q->where('sbooking_co_so_id', $sbCoSoForBs)->orWhere('xuat_hien_moi_co_so', true);
                                    })->where('active', true)->orderBy('ten')->get()
                                : collect();
                            $__svcNhom = null;
                            if ($newBookingServiceId) {
                                $__svcNhom = \App\Models\SbService::where('sbooking_id', $newBookingServiceId)->value('thuoc_nhom');
                            }
                            if ($__svcNhom === 'tu_van')  $bsList = $bsList->where('nhan_tu_van', true)->values();
                            if ($__svcNhom === 'kham_ls') $bsList = $bsList->where('nhan_kham_ls', true)->values();
                            $hasSlots = ! empty($availableSlots);
                            $slotHint = ! $newBookingRoomId ? 'Chọn phòng trước'
                                : (! $newBookingServiceId ? 'Chọn dịch vụ để load giờ'
                                    : (! $newBookingDate ? 'Chọn ngày trước'
                                        : ($hasSlots ? count($availableSlots) . ' khung giờ trong ngày để chọn' : 'Không có khung giờ phù hợp cho lựa chọn này')));
                        @endphp
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {{-- Cột 1: Địa điểm — Cơ sở → Phòng → Bác sĩ --}}
                            <div class="space-y-2">
                                <div class="text-[11px] uppercase tracking-wider font-semibold text-ink/50">Địa điểm</div>
                                <select wire:model.live="newBookingFacilityId" class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm">
                                    <option value="">— Cơ sở —</option>
                                    @foreach ($facilities as $fac)
                                        <optgroup label="{{ $fac->name }}">
                                            @foreach ($fac->children as $dept)
                                                <option value="{{ $dept->id }}">{{ $fac->name }} › {{ $dept->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                <div>
                                    <select wire:model.live="newBookingRoomId"
                                            @if(empty($availableRooms)) disabled @endif
                                            class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm {{ empty($availableRooms) ? 'bg-slate-100 text-ink/40' : '' }}">
                                        <option value="">{{ empty($availableRooms) ? '— Chọn cơ sở trước —' : '— Phòng * —' }}</option>
                                        @foreach ($availableRooms as $room)
                                            <option value="{{ $room['id'] }}">{{ $room['ten'] }} ({{ $room['kieu_phong'] === 'phong_dich_vu' ? 'DV' : 'Khám' }} · max {{ $room['so_slot_toi_da'] }})</option>
                                        @endforeach
                                    </select>
                                    @if ($roomStatus)
                                        <div class="mt-1 text-[11px] {{ $roomStatus['full'] ? 'text-red-700 font-semibold' : ($roomStatus['booked'] > 0 ? 'text-amber-700' : 'text-emerald-700') }}">
                                            @if ($roomStatus['full'])
                                                ⚠ Phòng đã đầy ({{ $roomStatus['booked'] }}/{{ $roomStatus['capacity'] }}) tại giờ đã chọn — sbooking sẽ chặn khi duyệt.
                                            @else
                                                Phòng còn {{ $roomStatus['capacity'] - $roomStatus['booked'] }}/{{ $roomStatus['capacity'] }} chỗ tại giờ đã chọn.
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <select wire:model.live="newBookingSbBacSiId"
                                        @if($bsList->isEmpty()) disabled @endif
                                        class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm {{ $bsList->isEmpty() ? 'bg-slate-100 text-ink/40' : '' }}">
                                    <option value="">{{ $bsList->isEmpty() ? '— Chọn cơ sở trước —' : '— Bác sĩ —' }}</option>
                                    @foreach ($bsList as $bs)
                                        <option value="{{ $bs->sbooking_id }}">{{ $bs->displayName() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Cột 2: Nội dung — Dịch vụ → Số lượng (gộp thay cho "Số liệu trình" + "Dung tích lọ") --}}
                            <div class="space-y-2">
                                <div class="text-[11px] uppercase tracking-wider font-semibold text-ink/50">Nội dung</div>
                                @php
                                    // 2026-08-09: 3 bucket filter.
                                    //   kham_ls → la_dich_vu=false AND thuoc_nhom=kham_ls
                                    //   tu_van  → la_dich_vu=false AND thuoc_nhom=tu_van
                                    //   dich_vu → la_dich_vu=true (mọi thuoc_nhom)
                                    $__bucketLabel = ['kham_ls' => 'khám lâm sàng', 'tu_van' => 'tư vấn', 'dich_vu' => 'dịch vụ'][$newBookingType] ?? '';
                                @endphp
                                <select wire:model.live="newBookingServiceId" @disabled(! $newBookingType) class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm">
                                    <option value="">
                                        {{ $newBookingType ? '— Chọn ' . $__bucketLabel . ' —' : '— Chọn loại trước —' }}
                                    </option>
                                    @if ($newBookingType)
                                        @php
                                            $__svcQuery = \App\Models\SbService::where('active', true);
                                            if ($newBookingType === 'dich_vu') {
                                                $__svcQuery->where('la_dich_vu', true);
                                            } else {
                                                $__svcQuery->where('la_dich_vu', false)->where('thuoc_nhom', $newBookingType);
                                            }
                                            if ($sbCoSoForBs) $__svcQuery->where(function ($q) use ($sbCoSoForBs) {
                                                $q->where('sbooking_co_so_id', $sbCoSoForBs)->orWhereNull('sbooking_co_so_id');
                                            });
                                            $__svcAll = $__svcQuery->orderBy('ten')->get();
                                            $__svcOptions = $__svcAll->sortByDesc(fn ($s) => $s->sbooking_co_so_id === $sbCoSoForBs ? 1 : 0)
                                                ->unique('ten')->sortBy('ten')->values();
                                        @endphp
                                        @foreach ($__svcOptions as $s)
                                            @php $__soon = stripos((string) $s->ten, '(sắp triển khai)') !== false; @endphp
                                            <option value="{{ $s->sbooking_id ?? $s->id }}" @disabled($__soon)>
                                                {{ $s->ten }}@if($s->thoi_gian_phut) ({{ $s->thoi_gian_phut }}') @endif
                                            </option>
                                        @endforeach
                                        @if ($__svcOptions->isEmpty())
                                            <option value="" disabled>(chưa có {{ $__bucketLabel }} — sync bên sbooking trước)</option>
                                        @endif
                                    @endif
                                </select>
                                <input wire:model="newBookingSoLuongLo" placeholder="Số lượng (VD: 3)" class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm">
                            </div>

                            {{-- Cột 3: Thời gian — Khung giờ --}}
                            <div class="space-y-2">
                                <div class="text-[11px] uppercase tracking-wider font-semibold text-ink/50">Thời gian</div>
                                <select wire:model.live="newBookingTime"
                                        @if(! $hasSlots) disabled @endif
                                        class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm {{ $hasSlots ? '' : 'bg-slate-100 text-ink/40' }}"
                                        title="{{ $slotHint }}">
                                    <option value="">— Khung giờ —</option>
                                    @foreach ($availableSlots as $slot)
                                        <option value="{{ ($slot['id'] ?? '') . '|' . ($slot['gio_bat_dau'] ?? '') . '|' . ($slot['gio_ket_thuc'] ?? '') }}" @if(($slot['full'] ?? false)) disabled @endif>
                                            {{ $slot['label'] ?? ($slot['gio_bat_dau'] ?? '') }}{{ ($slot['full'] ?? false) ? ' (đầy)' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="text-[11px] text-ink/50">{{ $slotHint }}</div>
                            </div>
                        </div>
                        {{-- 2026-08-05: CV auto lấy từ UPS Sale list (bucket A→B→C→OFF) của cơ sở booking.
                             Không chọn tay. + Thêm CV → tăng slot, tự pick sale kế tiếp. --}}
                        <div class="space-y-1.5">
                            <div class="text-xs font-semibold text-ink/60">
                                Nhân viên tiếp đón (⚡ auto UPS)
                                <span class="font-normal text-ink/40">— người đầu tiên = nhân viên tiếp đón chính khi booking được duyệt.</span>
                                @if ($cvPoolFacilityName)
                                    <span class="text-[10px] text-emerald-700 font-normal">· Cơ sở: <b>{{ $cvPoolFacilityName }}</b></span>
                                @endif
                            </div>
                            @if (empty($cvPreview))
                                <div class="text-xs bg-red-50 border border-red-300 text-red-800 rounded px-3 py-2">
                                    ⚠️ Chưa có UPS list Sale hôm nay cho cơ sở này — <b>không tạo được booking</b>. Liên hệ <b>Admin BO</b> để chốt UPS list trước.
                                </div>
                            @else
                                @foreach ($newBookingConsultantIds as $cvIdx => $cvVal)
                                    <div class="flex items-center gap-2" wire:key="new-cv-{{ $cvIdx }}">
                                        <span class="text-xs text-ink/50 w-6 text-right">#{{ $cvIdx + 1 }}</span>
                                        <div class="flex-1 border border-emerald-300 bg-emerald-50 rounded px-3 py-1.5 text-sm text-emerald-900">
                                            ⚡ {{ $cvPreview[$cvIdx]?->name ?? '—' }}
                                            <span class="text-[10px] text-emerald-700">(auto UPS)</span>
                                        </div>
                                        @if (count($newBookingConsultantIds) > 1)
                                            <button type="button" wire:click="removeBookingConsultantSlot({{ $cvIdx }})" class="text-xs text-red-600 hover:text-red-800 px-2">✕</button>
                                        @endif
                                    </div>
                                @endforeach
                                <button type="button" wire:click="addBookingConsultantSlot" class="text-xs font-semibold text-gold-700 hover:text-gold-800">+ Thêm nhân viên tiếp đón (kế tiếp trong UPS)</button>
                            @endif
                        </div>
                        {{-- 2026-08-09: 3 tick option 1 hàng ngang (bỏ Số liệu trình + Dung tích lọ — Số lượng đã gộp lên cột 2). --}}
                        <div class="flex flex-wrap items-center gap-6 pt-3 border-t border-slate-200">
                            <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
                                <input type="checkbox" wire:model="newBookingCoTuVan" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span>Có tư vấn</span>
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
                                <input type="checkbox" wire:model="newBookingCoKhamCls" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span>Có thăm khám lâm sàng</span>
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm cursor-pointer select-none">
                                <input type="checkbox" wire:model="newBookingKetHopMedical" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span>Kết hợp medical</span>
                            </label>
                        </div>
                        <input wire:model="newBookingNote" placeholder="Ghi chú" class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm">
                        <div class="flex justify-end">
                            <button type="button" wire:click="addBookingLog" class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-1.5 rounded">+ Tạo booking</button>
                        </div>
                    </div>
                @else
                    <p class="text-xs text-ink/40 italic">Bấm "Lưu thông tin khách hàng" để tạo lead trước.</p>
                @endif
            </div>
            @endif

            {{-- ============= Phase 4 rework 2026-08-01 — LỊCH SỬ BOOKING (list record) ============= --}}
            {{-- Mỗi booking = 1 record chứa cơ sở/bác sĩ/dịch vụ/CV[]. Chờ duyệt lên đầu. --}}
            <div x-show="phase === 3" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                    Lịch sử booking <span class="text-sm text-ink/50 font-normal">(mỗi lần đặt/đổi/hủy = 1 record — Chờ duyệt lên đầu)</span>
                </h2>
                {{-- 2026-08-04 (Task 2): dropdown filter status. Sale không có lead.book_action chỉ thấy booking mình là CV. --}}
                <div class="mb-3 flex items-center gap-2">
                    <label class="text-xs text-ink/60">Trạng thái:</label>
                    <select wire:model.live="bookingHistoryFilter" class="text-xs border border-slate-300 rounded px-2 py-1">
                        <option value="">Tất cả</option>
                        <option value="cho_xac_nhan">Chưa duyệt</option>
                        <option value="da_xac_nhan">Đã duyệt</option>
                        <option value="khach_da_toi">Khách đã tới</option>
                        <option value="khach_toi_tre">Khách tới trễ</option>
                        <option value="khach_huy">Khách hủy</option>
                        <option value="da_xong">Đã xong</option>
                    </select>
                    @if (! auth()->user()?->hasPermission('lead.book_action'))
                        <span class="text-xs text-ink/40 italic ml-auto">📌 Chỉ hiện booking mà bạn phụ trách (là CV)</span>
                    @endif
                </div>
                @php
                    $__uid = auth()->id();
                    $__canBookAction = auth()->user()?->hasPermission('lead.book_action');
                    $__filterQ = $lead?->exists ? $lead->bookingLogs()
                        ->with(['user', 'facility.parent', 'doctor', 'service', 'consultants'])
                        ->when(! $__canBookAction, fn ($q) => $q->whereHas('consultants', fn ($cq) => $cq->where('users.id', $__uid)))
                        ->when($this->bookingHistoryFilter !== '', function ($q) {
                            $f = $this->bookingHistoryFilter;
                            // Filter đọc từ 2 nguồn: booking_logs.status (chưa duyệt / đã duyệt / huy_doi_lich)
                            // + sync_status (checkedin/canceled/done từ callback sbooking).
                            $q->where(function ($sub) use ($f) {
                                $sub->where('status', $f);
                                match ($f) {
                                    'khach_da_toi', 'khach_toi_tre' => $sub->orWhere('sync_status', 'checkedin'),
                                    'khach_huy' => $sub->orWhere('sync_status', 'canceled'),
                                    'da_xong' => $sub->orWhere('sync_status', 'done'),
                                    default => null,
                                };
                            });
                        })
                        ->get() : collect();
                @endphp
                @if ($__filterQ->isNotEmpty())
                    @php
                        $blOrder = [\App\Models\BookingLog::STATUS_CHO_XAC_NHAN => 0, \App\Models\BookingLog::STATUS_DA_XAC_NHAN => 1, \App\Models\BookingLog::STATUS_HUY_DOI_LICH => 2];
                        $bookingList = $__filterQ
                            ->sort(function ($a, $b) use ($blOrder) {
                                $sa = $blOrder[$a->status] ?? 9;
                                $sb = $blOrder[$b->status] ?? 9;
                                if ($sa !== $sb) return $sa <=> $sb;
                                return ($b->scheduled_at?->timestamp ?? 0) <=> ($a->scheduled_at?->timestamp ?? 0);
                            })->values();
                    @endphp
                    <div class="border border-slate-200 rounded divide-y text-sm">
                        @foreach ($bookingList as $bl)
                            @php
                                $b = match ($bl->status) {
                                    'da_xac_nhan' => 'bg-emerald-100 text-emerald-700',
                                    'huy_doi_lich' => 'bg-red-100 text-red-700',
                                    default => 'bg-amber-100 text-amber-700 ring-1 ring-amber-300',
                                };
                                $tb = $bl->type === 'tham_kham' ? 'bg-sky-100 text-sky-700' : ($bl->type === 'dich_vu' ? 'bg-fuchsia-100 text-fuchsia-700' : 'bg-slate-100 text-slate-500');
                                $tlabel = $bl->type === 'tham_kham' ? '🩺 Thăm khám' : ($bl->type === 'dich_vu' ? '💆 Dịch vụ' : 'Chưa gán loại');
                                $facLabel = $bl->facility ? (($bl->facility->parent?->name ? $bl->facility->parent->name . ' › ' : '') . $bl->facility->name) : '—';
                            @endphp
                            <div class="p-3 space-y-1.5">
                                <div class="flex items-center flex-wrap gap-2">
                                    <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap {{ $tb }}">{{ $tlabel }}</span>
                                    <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap {{ $b }}">{{ $bl->statusLabel() }}</span>
                                    @php
                                        $__schedText = 'chưa đặt';
                                        if ($bl->scheduled_at) {
                                            $__schedText = $bl->scheduled_at->format('d/m/Y H:i');
                                            // Ước lượng giờ kết thúc theo thoi_gian_phut của sb_service (map theo tên).
                                            if ($bl->service) {
                                                $__phut = \App\Models\SbService::where('ten', $bl->service->name)->value('thoi_gian_phut');
                                                if ($__phut) {
                                                    $__schedText .= '–' . $bl->scheduled_at->copy()->addMinutes((int) $__phut)->format('H:i');
                                                }
                                            }
                                        }
                                    @endphp
                                    <span class="text-xs text-ink/50">Lịch: <b class="text-ink/80">{{ $__schedText }}</b></span>
                                    <span class="text-xs text-ink/40 ml-auto">Người book: {{ $bl->user->name ?? 'system' }}</span>
                                </div>
                                <div class="text-xs text-ink/70 flex flex-wrap gap-x-4 gap-y-1">
                                    <span>🏥 <b>{{ $facLabel }}</b></span>
                                    <span>👨‍⚕️ BS: <b>{{ $bl->doctor->name ?? '—' }}</b></span>
                                    <span>💊 DV: <b>{{ $bl->service->name ?? '—' }}</b></span>
                                    <span>🧑‍💼 CV:
                                        @if ($bl->consultants->isNotEmpty())
                                            <b>{{ $bl->consultants->pluck('name')->implode(', ') }}</b>
                                        @else
                                            <span class="italic text-ink/40">—</span>
                                        @endif
                                    </span>
                                </div>
                                {{-- Phase C1.e: inline edit note + auto push sbooking --}}
                                @if ($editingBookingId === $bl->id)
                                    <div class="flex items-start gap-1.5">
                                        <textarea wire:model="editingBookingNotes.{{ $bl->id }}" rows="2"
                                                  class="flex-1 text-xs border border-blue-300 rounded px-2 py-1 focus:outline-none focus:border-blue-500"
                                                  placeholder="Ghi chú..."></textarea>
                                        <button type="button" wire:click="saveBookingNote({{ $bl->id }})"
                                                class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-2 py-1 rounded">💾 Lưu</button>
                                        <button type="button" wire:click="cancelEditBookingNote"
                                                class="text-xs border border-slate-300 text-ink/60 hover:bg-slate-50 px-2 py-1 rounded">Hủy</button>
                                    </div>
                                @else
                                    <div class="text-ink/80 text-xs italic flex items-start gap-1.5">
                                        <span class="flex-1">📝 {{ $bl->note ?: '(chưa có ghi chú)' }}</span>
                                        <button type="button" wire:click="startEditBookingNote({{ $bl->id }})"
                                                class="text-[10px] text-blue-600 hover:text-blue-800 shrink-0"
                                                title="{{ $bl->sbooking_booking_id ? 'Sửa + tự đồng bộ sbooking' : 'Sửa (chưa sync sbooking)' }}">
                                            ✏️ Sửa
                                        </button>
                                    </div>
                                @endif
                                {{-- Phase C1.b 2026-08-01: badge sync + retry --}}
                                @if ($bl->sync_status === 'done')
                                    <div class="text-xs text-purple-800 font-semibold flex items-center gap-1.5">
                                        🏁 Đã xong · <b>{{ $bl->sbooking_booking_ma ?: '#'.$bl->sbooking_booking_id }}</b>
                                        @if ($bl->synced_at) <span class="text-ink/40 font-normal">· {{ $bl->synced_at->diffForHumans() }}</span> @endif
                                    </div>
                                @elseif ($bl->sync_status === 'checkedin')
                                    <div class="text-xs text-teal-800 font-semibold flex items-center gap-1.5">
                                        🚪 Khách đã tới · <b>{{ $bl->sbooking_booking_ma ?: '#'.$bl->sbooking_booking_id }}</b>
                                        @if ($bl->synced_at) <span class="text-ink/40 font-normal">· {{ $bl->synced_at->diffForHumans() }}</span> @endif
                                    </div>
                                @elseif ($bl->sync_status === 'canceled')
                                    <div class="text-xs text-orange-800 font-semibold flex items-center gap-1.5">
                                        🚫 Khách hủy · <b>{{ $bl->sbooking_booking_ma ?: '#'.$bl->sbooking_booking_id }}</b>
                                        @if ($bl->synced_at) <span class="text-ink/40 font-normal">· {{ $bl->synced_at->diffForHumans() }}</span> @endif
                                    </div>
                                @elseif ($bl->sync_status === 'approved')
                                    <div class="text-xs text-emerald-800 font-semibold flex items-center gap-1.5">
                                        ✅ Sbooking đã duyệt · <b>{{ $bl->sbooking_booking_ma ?: '#'.$bl->sbooking_booking_id }}</b>
                                        @if ($bl->synced_at) <span class="text-ink/40 font-normal">· {{ $bl->synced_at->diffForHumans() }}</span> @endif
                                    </div>
                                @elseif ($bl->sync_status === 'synced')
                                    <div class="text-xs text-blue-700 flex items-center gap-1.5">
                                        ⏳ Đã gửi sbooking, chờ duyệt · <b>{{ $bl->sbooking_booking_ma ?: '#'.$bl->sbooking_booking_id }}</b>
                                        @if ($bl->synced_at) <span class="text-ink/40">· {{ $bl->synced_at->diffForHumans() }}</span> @endif
                                    </div>
                                @elseif ($bl->sync_status === 'deleted')
                                    <div class="text-xs text-slate-700 font-semibold flex items-center gap-1.5 line-through">
                                        🗑 Đã bị Admin sbooking xóa
                                        @if ($bl->synced_at) <span class="text-ink/40 font-normal no-underline">· {{ $bl->synced_at->diffForHumans() }}</span> @endif
                                    </div>
                                @elseif ($bl->sync_status === 'rejected')
                                    <div class="text-xs text-rose-700 font-semibold flex items-center gap-1.5">
                                        ❌ Sbooking từ chối
                                        @if ($bl->sync_error) <span class="font-normal">— {{ $bl->sync_error }}</span> @endif
                                        @if ($bl->synced_at) <span class="text-ink/40 font-normal">· {{ $bl->synced_at->diffForHumans() }}</span> @endif
                                    </div>
                                @elseif ($bl->sync_status === 'failed')
                                    <div class="text-xs text-red-700 flex items-center gap-2">
                                        <span>⚠ Chưa đồng bộ sbooking: {{ $bl->sync_error }}</span>
                                        <button type="button" wire:click="retrySbookingSync({{ $bl->id }})"
                                                wire:loading.attr="disabled" wire:target="retrySbookingSync({{ $bl->id }})"
                                                class="text-xs bg-white border border-red-300 text-red-700 hover:bg-red-50 px-2 py-0.5 rounded">
                                            🔄 Thử lại
                                        </button>
                                    </div>
                                @elseif ($bl->sync_status === 'pending')
                                    <div class="text-xs text-amber-700">⏳ Đang đồng bộ sbooking…</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-ink/40 italic">
                        @if ($lead?->exists && $lead->bookingLogs->isNotEmpty())
                            Không có booking khớp bộ lọc hiện tại.
                        @else
                            Chưa có booking nào.
                            @if (auth()->user()?->hasPermission('lead.book_action')) Dùng khung "Tạo booking" bên dưới để tạo record mới. @endif
                        @endif
                    </p>
                @endif
            </div>

            {{-- INSIGHT — Phase 3 (Gọi điện) — order 3 (dưới cùng) --}}
            <div x-show="phase === 2" x-cloak class="order-3 bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/></svg>
                    INSIGHT
                </h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Ghi chú insight khách</label>
                        <textarea wire:model="insight" rows="2" placeholder="Ghi chú insight khách hàng..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                    {{-- 2026-08-11: field "Link" đã move sang phase 1 dưới dạng custom field (cấp công ty, key=link). --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Ngày sinh</label>
                            <x-date-input field="birthday" />
                            @error('birthday')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">Nghề nghiệp</label>
                            <input type="text" wire:model="occupation" placeholder="VD: Kinh doanh, Nội trợ..." class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Địa chỉ</label>
                        <input type="text" wire:model="address" placeholder="Địa chỉ khách hàng" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Khai thác tiền sử</label>
                        <textarea wire:model="medical_history" rows="2" placeholder="Bệnh lý, trước đó đã sử dụng dịch vụ gì, ở đâu,...?" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500"></textarea>
                    </div>
                </div>
            </div>

            {{-- Nhóm LIỆU TRÌNH — Phase 7 (Sử dụng DV) --}}
            <div x-show="phase === 7" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="font-bold text-gold-700 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                        LIỆU TRÌNH
                    </h2>
                    <button type="button" wire:click="addTreatmentRow"
                            class="inline-flex items-center gap-1.5 text-sm font-semibold text-white bg-gold-600 hover:bg-gold-700 px-3 py-1.5 rounded-md">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        Thêm liệu trình
                    </button>
                </div>

                @if (empty($treatmentRows))
                    <p class="text-sm text-ink/40 italic">Chưa có lần liệu trình nào. Bấm "Thêm liệu trình" để bắt đầu.</p>
                @endif

                <div class="space-y-3">
                    @foreach ($treatmentRows as $idx => $row)
                        <div class="border border-gold-200 rounded-lg p-4 bg-gold-50/30 relative" wire:key="tr-{{ $idx }}">
                            <button type="button" wire:click="removeTreatmentRow({{ $idx }})"
                                    class="absolute top-2 right-2 text-xs font-semibold text-red-600 hover:text-red-800 inline-flex items-center gap-1"
                                    title="Xoá lần {{ $idx + 1 }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>

                            <div class="text-xs font-bold uppercase tracking-wider text-gold-700 mb-3">Lần {{ $idx + 1 }}</div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block text-xs font-medium text-ink/60 mb-1">Ngày thực hiện</label>
                                    <x-date-input :field="'treatmentRows.' . $idx . '.performed_at'" />
                                    @error("treatmentRows.{$idx}.performed_at")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-ink/60 mb-1">Bác sĩ thực hiện</label>
                                    <select wire:model="treatmentRows.{{ $idx }}.performing_doctor_id"
                                            class="w-full border border-gold-200 rounded-md px-2 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                        <option value="">— Chọn bác sĩ —</option>
                                        @foreach ($allStaff->where('role', 'doctor') as $doc)
                                            <option value="{{ $doc->id }}">{{ $doc->name }}{{ $doc->title ? ' — ' . $doc->title : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error("treatmentRows.{$idx}.performing_doctor_id")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-ink/60 mb-1">Đánh giá chất lượng chuyên môn</label>
                                <textarea wire:model="treatmentRows.{{ $idx }}.quality_rating" rows="2"
                                          placeholder="Ghi nhận đánh giá lần này..."
                                          class="w-full border border-gold-200 rounded-md px-3 py-2 text-sm focus:outline-none focus:border-gold-500"></textarea>
                                @error("treatmentRows.{$idx}.quality_rating")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- 2026-08-11: Section "Ghi nhận tình trạng" phase 2 đã ẩn theo yêu cầu (Phân loại + tình trạng 1 + tình trạng 2).
                 Field vẫn còn trong DB (classification default 'new', status_1/2 nullable) để không vỡ save/query. --}}

            {{-- DV tiềm năng + UPSELL — Phase 6 (Bán hàng) --}}
            <div x-show="phase === 5" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.281m5.94 2.28l-2.28 5.941"/></svg>
                    Dịch vụ tiềm năng & UPSELL
                </h2>
                <div class="space-y-4">
                    {{-- Dịch vụ tiềm năng — chọn từ danh mục services --}}
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Dịch vụ tiềm năng</label>
                        <select wire:model="potential_service" class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm bg-white focus:outline-none focus:border-gold-500">
                            <option value="">— Chọn dịch vụ —</option>
                            @foreach ($serviceTree as $cat)
                                <optgroup label="{{ $cat->name }}">
                                    @foreach ($cat->children as $child)
                                        @if ($child->children->isNotEmpty())
                                            @foreach ($child->children as $leaf)
                                                <option value="{{ $leaf->name }}">{{ $child->name }} · {{ $leaf->name }}</option>
                                            @endforeach
                                        @else
                                            <option value="{{ $child->name }}">{{ $child->name }}</option>
                                        @endif
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>

                    <div class="border-t border-gold-100 pt-4">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-bold uppercase tracking-wider text-ink/40">UPSELL — Doanh số phát sinh</p>
                            <button type="button" wire:click="addUpsellRow"
                                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700 border border-gold-300 hover:bg-gold-50 px-3 py-1.5 rounded-md">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                Upsell phát sinh
                            </button>
                        </div>
                        @error('upsellRows')<p class="text-xs text-red-600 mb-2">{{ $message }}</p>@enderror
                        <div class="space-y-3">
                            @foreach ($upsellRows as $idx => $row)
                                <div wire:key="upsell-{{ $idx }}" class="bg-gold-50/50 border border-gold-100 rounded-lg p-3">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-1 space-y-2">
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-xs font-medium text-ink/60 mb-1">Chuyên viên tư vấn</label>
                                                    <select wire:model="upsellRows.{{ $idx }}.staff_member_id"
                                                            class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                                        <option value="">— Chọn CVTV —</option>
                                                        @foreach ($assignedConsultants as $cv)
                                                            <option value="{{ $cv['id'] }}">{{ $cv['name'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-ink/60 mb-1">Số tiền (₫)</label>
                                                    <input type="text" wire:model="upsellRows.{{ $idx }}.amount" placeholder="0"
                                                           x-data x-on:input="$el.value = $el.value.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')"
                                                           class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm font-mono focus:outline-none focus:border-gold-500">
                                                    @error("upsellRows.{$idx}.amount")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                                                </div>
                                            </div>
                                            <div x-data="{
                                                tree: {{ $svcTreeJson }},
                                                search: '', open: false, selected: null,
                                                modelValue: @entangle('upsellRows.' . $idx . '.service_id'),
                                                init() { this.resolveSelected(); this.$watch('modelValue', () => this.resolveSelected()); },
                                                resolveSelected() {
                                                    if (!this.modelValue) { this.selected = null; return; }
                                                    for (const c of this.tree) for (const ch of c.children) {
                                                        if (!ch.is_cat && ch.id == this.modelValue) { this.selected = ch.name; return; }
                                                        for (const s of (ch.children||[])) if (s.id == this.modelValue) { this.selected = s.name; return; }
                                                    }
                                                },
                                                get filtered() {
                                                    const q = this.search.toLowerCase().trim();
                                                    if (!q) return this.tree;
                                                    return this.tree.map(cat => {
                                                        const fc = cat.children.map(ch => {
                                                            if (ch.is_cat) { const fs = ch.children.filter(s => s.name.toLowerCase().includes(q)||(s.code&&s.code.toLowerCase().includes(q))); return fs.length?{...ch,children:fs}:null; }
                                                            return (ch.name.toLowerCase().includes(q)||(ch.code&&ch.code.toLowerCase().includes(q)))?ch:null;
                                                        }).filter(Boolean);
                                                        return fc.length?{...cat,children:fc}:null;
                                                    }).filter(Boolean);
                                                },
                                                pick(item) { this.modelValue = item.id; this.selected = item.name; this.search = ''; this.open = false; },
                                                clear() { this.modelValue = ''; this.selected = null; this.search = ''; }
                                            }">
                                                <label class="block text-xs font-medium text-ink/60 mb-1">Dịch vụ</label>
                                                <div class="relative">
                                                    <input type="text" x-model="search" @focus="open = true" @click="open = true"
                                                           :placeholder="selected ? selected : 'Tìm dịch vụ...'"
                                                           class="w-full border border-gold-200 rounded-md px-2.5 py-2 text-sm bg-white focus:outline-none focus:border-gold-500">
                                                    <div x-show="open" @click.outside="open = false" x-cloak
                                                         class="absolute z-40 left-0 right-0 top-full mt-1 bg-white border border-gold-200 rounded-xl shadow-lg max-h-60 overflow-y-auto">
                                                        <template x-for="cat in filtered" :key="cat.id">
                                                            <div>
                                                                <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-ink/40 bg-gold-50 sticky top-0" x-text="cat.name"></div>
                                                                <template x-for="child in cat.children" :key="child.id">
                                                                    <div>
                                                                        <template x-if="child.is_cat">
                                                                            <div>
                                                                                <div class="pl-4 pr-3 py-1 text-[10px] font-semibold text-ink/50" x-text="child.name"></div>
                                                                                <template x-for="sub in child.children" :key="sub.id">
                                                                                    <button type="button" @click="pick(sub)"
                                                                                            class="w-full text-left pl-7 pr-3 py-1.5 text-sm hover:bg-gold-50">
                                                                                        <span x-text="sub.name"></span>
                                                                                    </button>
                                                                                </template>
                                                                            </div>
                                                                        </template>
                                                                        <template x-if="!child.is_cat">
                                                                            <button type="button" @click="pick(child)"
                                                                                    class="w-full text-left pl-4 pr-3 py-1.5 text-sm hover:bg-gold-50">
                                                                                <span x-text="child.name"></span>
                                                                            </button>
                                                                        </template>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                        <div x-show="filtered.length === 0" class="px-3 py-3 text-sm text-ink/40 text-center">Không tìm thấy</div>
                                                    </div>
                                                </div>
                                                @error("upsellRows.{$idx}.service_id")<p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>@enderror
                                            </div>
                                        </div>
                                        <button type="button" wire:click="removeUpsellRow({{ $idx }})"
                                                class="mt-5 text-red-400 hover:text-red-600 p-1" title="Xóa">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if (empty($upsellRows))
                            <p class="text-sm text-ink/40 italic">Chưa có doanh số phát sinh. Bấm "+ Upsell phát sinh" để thêm.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- "Phân phối & Nguồn" đã gộp vào tab Trạng thái (khi $canDistribute). --}}

            {{-- ============= Phase 6.21g — Section CHIA SỐ (Phase 2) — panel Phân phối động ============= --}}
            <div x-show="phase === 1" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                    Chia số (Phân phối)
                </h2>

                {{-- Info readonly hiện tại --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm mb-4">
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Nguồn khách</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ \App\Models\Lead::SOURCE_GROUPS[$sourceGroup] ?? '—' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Team đang giữ</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ $lead?->orgUnit?->name ?? '— chưa chia —' }}</div>
                    </div>
                    @php
                        $_isBooking = $lead?->pipeline_phase === \App\Models\Lead::PHASE_BOOKING;
                        $_ownerLabel = $_isBooking ? 'Tele phụ trách' : 'Sale phụ trách';
                        $_importedName = $lead?->imported_by
                            ? \App\Models\User::find($lead->imported_by)?->name
                            : $lead?->receiver?->name;
                    @endphp
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">{{ $_ownerLabel }}</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ $lead?->owner?->name ?? '— chưa chia —' }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink/60 mb-1">Người nhập</label>
                        <div class="px-3 py-2 border border-gold-200 rounded-md bg-slate-50">{{ $_importedName ?? '—' }}</div>
                    </div>
                </div>

                {{-- 2026-08-05: nguồn MKT — radio Cách chia (hiện cho MỌI user, kể cả trực page không có perm distribute).
                     Option 'manual' chỉ hiện khi user có perm lead.assign_direct (CM sale/CM Tele/DM HCM/Manager/Admin). --}}
                @if (in_array($sourceGroup, [\App\Models\Lead::SOURCE_MKT, \App\Models\Lead::SOURCE_MKT_BR], true) && ! $lead?->exists)
                    @php $__canAssignDirect = auth()->user()->hasPermission('lead.assign_direct'); @endphp
                    <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-md">
                        <label class="block text-sm font-bold text-amber-900 mb-2">🎯 Cách chia lead (nguồn Marketing) <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-1 sm:grid-cols-{{ $__canAssignDirect ? '3' : '2' }} gap-2">
                            <label class="flex items-start gap-2 border rounded-md px-3 py-2.5 text-sm cursor-pointer bg-white hover:bg-amber-50 {{ $mktMode === 'auto' ? 'border-amber-500 ring-2 ring-amber-300' : 'border-gold-200' }}">
                                <input type="radio" wire:model.live="mktMode" value="auto" class="mt-0.5">
                                <span>
                                    <b>⚡ Tự động</b>
                                    <span class="block text-[11px] text-ink/60">Chia ngay từ UPS list @if ($mktFacilityName)của <b>{{ $mktFacilityName }}</b> @endif(round-robin).</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-2 border rounded-md px-3 py-2.5 text-sm cursor-pointer bg-white hover:bg-amber-50 {{ $mktMode === 'pool' ? 'border-amber-500 ring-2 ring-amber-300' : 'border-gold-200' }}">
                                <input type="radio" wire:model.live="mktMode" value="pool" class="mt-0.5">
                                <span>
                                    <b>📥 Chia về kho</b>
                                    <span class="block text-[11px] text-ink/60">Thả vào kho <b>{{ $mktPoolTargetLabel ?? '—' }}</b> (theo phân quyền). Chờ CM chia tiếp.</span>
                                </span>
                            </label>
                            @if ($__canAssignDirect)
                                <label class="flex items-start gap-2 border rounded-md px-3 py-2.5 text-sm cursor-pointer bg-white hover:bg-amber-50 {{ $mktMode === 'manual' ? 'border-amber-500 ring-2 ring-amber-300' : 'border-gold-200' }}">
                                    <input type="radio" wire:model.live="mktMode" value="manual" class="mt-0.5">
                                    <span>
                                        <b>👤 Thủ công - Chọn nhân sự</b>
                                        <span class="block text-[11px] text-ink/60">Giao thẳng cho 1 nhân sự trong phạm vi của bạn (phase 2 gọi điện, không qua UPS).</span>
                                    </span>
                                </label>
                            @endif
                        </div>
                        @error('mktMode')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror

                        {{-- Dropdown chọn nhân sự khi mode=manual --}}
                        @if ($mktMode === 'manual' && $__canAssignDirect)
                            <div class="mt-3">
                                <label class="block text-xs font-medium text-amber-900 mb-1">Chọn nhân sự nhận lead <span class="text-red-500">*</span></label>
                                <select wire:model="manualAssignUserId" class="w-full border border-amber-300 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:border-amber-500">
                                    <option value="">— chọn nhân sự trong phạm vi của bạn —</option>
                                    @foreach ($manualAssignableUsers as $__u)
                                        <option value="{{ $__u->id }}">{{ $__u->name }} <small>({{ $__u->email }})</small></option>
                                    @endforeach
                                </select>
                                @error('manualAssignUserId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                @if ($manualAssignableUsers->isEmpty())
                                    <p class="text-xs text-amber-700 mt-1 italic">⚠ Chưa có nhân sự nào trong phạm vi. Kiểm tra assignment của bạn.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                {{-- 2026-08-05: Form Phân phối — hiện cho user có perm chia HOẶC Trực Page up MKT
                     (Trực Page cần xem Check UPS + preview sale khi chọn Tự động, dù không có lead.distribute). --}}
                @if ($canDistribute || (in_array($sourceGroup, [\App\Models\Lead::SOURCE_MKT, \App\Models\Lead::SOURCE_MKT_BR], true) && ! $lead?->exists))
                    <div class="border-t border-gold-200 pt-5">
                        @php $upsBlockedHere = app(\App\Services\Ups\UpsGate::class)->isBlockedFor(auth()->user()); @endphp
                        <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
                            <h3 class="font-bold text-gold-700 flex items-center gap-2 text-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                                Chia lead vào kho / cho sale
                            </h3>
                            {{-- 2026-08-04: nút Check UPS mở popup danh sách sale + tình trạng (không nhảy trang). --}}
                            <div x-data="{ show: false }" class="relative">
                                <button type="button" @click="show = !show"
                                        class="text-xs font-bold px-3 py-1.5 rounded whitespace-nowrap inline-flex items-center gap-1.5 {{ $upsBlockedHere ? 'bg-red-600 hover:bg-red-700 text-white animate-pulse' : 'bg-white border border-gold-300 text-gold-700 hover:bg-gold-50' }}">
                                    ⚡ Check UPS System
                                    @if ($upsBlockedHere)<span class="text-[10px] bg-white/20 px-1 rounded">chưa chốt</span>@endif
                                </button>
                                {{-- Popup: liệt kê sale check-in hôm nay + trạng thái busy/free. --}}
                                {{-- 2026-08-04 mobile: dùng w calc để không tràn mép trên iPhone 11 (390-430px). --}}
                                <div x-show="show" @click.outside="show = false" x-transition x-cloak
                                     class="absolute right-0 mt-2 w-[calc(100vw-2rem)] sm:w-80 max-w-sm bg-white border border-gold-200 rounded-lg shadow-xl z-30 max-h-96 overflow-auto">
                                    @php
                                        $__today = now()->toDateString();
                                        $__atts = \App\Models\DailyAttendance::with('user')
                                            ->whereDate('work_date', $__today)
                                            ->orderBy('list_bucket')->orderBy('checkin_at')->get();
                                    @endphp
                                    <div class="px-3 py-2 border-b border-gold-100 bg-gold-50 flex items-center justify-between">
                                        <div class="text-xs font-bold text-gold-800">UPS hôm nay · {{ $__atts->count() }} sale check-in</div>
                                        <a href="{{ route(auth()->user()->hasPermission('ups.view') ? 'ups.list' : 'ups.today') }}" target="_blank" class="text-[11px] text-gold-700 hover:underline">Xem đầy đủ →</a>
                                    </div>
                                    @if ($__atts->isEmpty())
                                        <div class="px-3 py-6 text-center text-xs text-ink/40">Chưa có sale nào check-in hôm nay.</div>
                                    @else
                                        <ul class="divide-y divide-gold-50">
                                            @foreach ($__atts as $__a)
                                                @php
                                                    $__busy = (bool) $__a->is_busy;
                                                    $__off  = (bool) $__a->is_off;
                                                    $__label = $__off ? 'Offlist' : ($__busy ? 'Đang tiếp đón' : 'Đang chờ');
                                                    $__cls   = $__off ? 'bg-rose-100 text-rose-700' : ($__busy ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700');
                                                    $__bkc   = match ($__a->list_bucket) { 'A'=>'bg-blue-100 text-blue-800', 'B'=>'bg-teal-100 text-teal-800', 'C'=>'bg-slate-200 text-slate-800', 'OFF'=>'bg-rose-100 text-rose-800', 'MKT'=>'bg-amber-100 text-amber-800', default=>'bg-gold-100 text-gold-800' };
                                                @endphp
                                                <li class="px-3 py-2 flex items-center gap-2 hover:bg-gold-50/40">
                                                    <div class="flex-1 min-w-0">
                                                        <div class="text-sm font-semibold text-ink truncate">{{ $__a->user?->name ?? 'Sale #'.$__a->user_id }}</div>
                                                        <div class="text-[11px] text-ink/50">Check-in {{ optional($__a->checkin_at)?->setTimezone('Asia/Ho_Chi_Minh')->format('H:i') }}</div>
                                                    </div>
                                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded {{ $__bkc }}">{{ $__a->list_bucket }}</span>
                                                    <span class="text-[11px] font-semibold px-2 py-0.5 rounded {{ $__cls }} whitespace-nowrap">{{ $__label }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if ($upsBlockedHere)
                            <div class="bg-red-50 border border-red-300 text-red-800 text-xs px-3 py-2 rounded mb-3">
                                <strong>UPS chưa được chốt hôm nay</strong> — chia số sẽ bị khóa. Bấm nút bên trên để xem/chốt.
                            </div>
                        @endif
                        {{-- 2026-08-05: nguồn MKT create → chỉ hiện cascade+nhân viên khi mode=pool.
                             Auto dùng UPS list, manual dùng dropdown ở card. --}}
                        @php
                            $__isMktCreate = (in_array($sourceGroup, [\App\Models\Lead::SOURCE_MKT, \App\Models\Lead::SOURCE_MKT_BR], true) && ! $lead?->exists);
                            $__hideCascade = $__isMktCreate && $mktMode !== 'pool';
                            $__nextSaleObj = $mktNextSale['sale'] ?? null;
                            $__rotated = $mktNextSale['rotated'] ?? false;
                            $__boxCls = $__rotated
                                ? 'bg-amber-50 border-amber-300 text-amber-900'
                                : 'bg-emerald-50 border-emerald-300 text-emerald-900';
                            $__subCls = $__rotated ? 'text-amber-700' : 'text-emerald-700';
                        @endphp
                        @if ($__hideCascade && $mktMode === 'auto')
                            <div class="{{ $__boxCls }} border rounded-md px-3 py-3 text-sm">
                                <div class="font-bold mb-2">⚡ Chia tự động — dự kiến:</div>
                                {{-- 2026-08-11: bỏ dropdown chọn cơ sở tay — admin chọn trên navbar (AdminScope) là đủ. --}}
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div class="bg-white/70 rounded px-3 py-2">
                                        <div class="text-[11px] uppercase tracking-wide {{ $__subCls }}">Cơ sở tiếp nhận</div>
                                        <div class="font-semibold">{{ $mktFacilityName ?? '⚠️ Chưa xác định' }}</div>
                                    </div>
                                    <div class="bg-white/70 rounded px-3 py-2">
                                        <div class="text-[11px] uppercase tracking-wide {{ $__subCls }}">Sale sẽ nhận (round-robin)</div>
                                        <div class="font-semibold">
                                            @if ($__nextSaleObj)
                                                {{ $__nextSaleObj->name }}
                                                <span class="text-[11px] font-normal text-ink/50">({{ $__nextSaleObj->email }})</span>
                                                @if ($__rotated)
                                                    <div class="text-[11px] font-normal text-amber-800 mt-1">⚠️ List sale đón tiếp đang full, xoay vòng trở lại — {{ $__nextSaleObj->name }}</div>
                                                @endif
                                            @else
                                                <span class="text-red-700">⚠️ Chưa có sale trong MKT List UPS hôm nay — bấm Lưu sẽ báo lỗi. Liên hệ BO chốt UPS.</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                @if ($mktListToday->count())
                                    <div class="mt-2 text-[11px] {{ $__subCls }}">
                                        <b>MKT List hôm nay ({{ $mktListToday->count() }}):</b>
                                        @foreach ($mktListToday as $__mk)
                                            <span class="inline-block ml-1 px-1.5 py-0.5 rounded {{ $__nextSaleObj && $__mk->user_id === $__nextSaleObj->id ? ($__rotated ? 'bg-amber-600 text-white font-semibold' : 'bg-emerald-600 text-white font-semibold') : ($__mk->is_busy ? 'bg-amber-100 text-amber-800 line-through' : 'bg-white border border-emerald-300') }}">{{ $__mk->user?->name ?? '?' }}@if ($__mk->is_busy) 🔴@endif</span>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="mt-2 text-[11px] text-emerald-700 italic">💡 Bấm <b>Lưu</b> ở footer để chia thật. Sale được đánh dấu <b>xanh đậm</b> là người sẽ nhận lead này.</div>
                            </div>
                        @endif
                        {{-- 2026-08-05: cascade + person section — chỉ user có perm distribute thấy. Trực Page ẩn.
                             Nếu chưa chọn nguồn khách → hiện placeholder "Vui lòng chọn nguồn khách". --}}
                        @if ($canDistribute && ! $sourceGroup && ! $lead?->exists)
                            <div class="border border-dashed border-gold-300 rounded-md px-4 py-6 text-center text-sm text-ink/50 italic">
                                Vui lòng chọn nguồn khách ở trên để hiện form chia số.
                            </div>
                        @endif
                        @if ($canDistribute && ($sourceGroup || $lead?->exists))
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 @if ($__hideCascade) hidden @endif">
                            @if ($lead?->code)
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium mb-1.5">Mã khách hàng</label>
                                <p class="text-sm"><code class="font-mono text-gold-700">{{ $lead->code }}</code></p>
                            </div>
                            @endif
                            {{-- 2026-08-03 cascade: Công ty (readonly) → Địa điểm → Cơ sở → Phòng ban. Toggle "Kho chung" phía trên. --}}
                            <div>
                                <label class="block text-sm font-medium mb-1.5">Chia số <span class="text-xs font-normal text-ink/50">(chọn cấp thấp nhất muốn áp — được phép dừng ở bất kỳ cấp nào)</span></label>
                                @php $__company = \App\Models\PoolUnit::where('kind','company')->first(); @endphp
                                <div class="space-y-2">
                                    <label class="flex items-center gap-2 text-sm bg-slate-50 border border-gold-200 rounded-md px-3 py-2 {{ $selectedPerson ? 'opacity-50 cursor-not-allowed' : '' }}">
                                        <input type="checkbox" wire:model.live="poolCompanyMode" @disabled($selectedPerson) class="rounded border-gold-300">
                                        <span>🏢 Kho chung công ty <span class="text-ink/40">({{ $__company?->name ?? 'Longevity Medical' }} — mọi Sale trong công ty thấy được)</span></span>
                                    </label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 {{ $poolCompanyMode ? 'opacity-40 pointer-events-none' : '' }}">
                                        <div>
                                            <label class="block text-[10px] text-ink/50 mb-1">Công ty</label>
                                            <input type="text" readonly value="{{ $__company?->name ?? 'Longevity Medical' }}"
                                                   class="w-full border border-gold-200 rounded-md px-2 py-2 text-sm bg-slate-50 text-ink/70 cursor-not-allowed">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] text-ink/50 mb-1">Địa điểm</label>
                                            <select wire:model.live="poolBranchId" @disabled($selectedPerson || $poolCompanyMode) class="w-full border border-gold-200 rounded-md px-2 py-2 text-sm bg-white">
                                                <option value="">— chọn địa điểm —</option>
                                                @foreach (\App\Models\PoolUnit::where('is_active',true)->where('kind','branch')->orderBy('sort')->orderBy('name')->get() as $b)
                                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[10px] text-ink/50 mb-1">Cơ sở</label>
                                            <select wire:model.live="poolFacilityId" @disabled($selectedPerson || $poolCompanyMode || ! $poolBranchId) class="w-full border border-gold-200 rounded-md px-2 py-2 text-sm bg-white disabled:bg-slate-100">
                                                <option value="">{{ $poolBranchId ? '— chọn cơ sở —' : '— chọn địa điểm trước —' }}</option>
                                                @foreach (($poolBranchId ? \App\Models\PoolUnit::where('is_active',true)->where('kind','facility')->where('parent_id',$poolBranchId)->orderBy('sort')->orderBy('name')->get() : []) as $f)
                                                    <option value="{{ $f->id }}">{{ $f->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[10px] text-ink/50 mb-1">Phòng ban</label>
                                            <select wire:model.live="poolDepartmentId" @disabled($selectedPerson || $poolCompanyMode || ! $poolFacilityId) class="w-full border border-gold-200 rounded-md px-2 py-2 text-sm bg-white disabled:bg-slate-100">
                                                <option value="">{{ $poolFacilityId ? '— chọn phòng ban —' : '— chọn cơ sở trước —' }}</option>
                                                @foreach (($poolFacilityId ? \App\Models\PoolUnit::where('is_active',true)->where('kind','department')->where('parent_id',$poolFacilityId)->orderBy('sort')->orderBy('name')->get() : []) as $d)
                                                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-xs text-ink/50 mt-1.5">
                                    @if ($selectedPerson)Đã gán nhân viên phụ trách → lead không nằm trong kho chung.
                                    @else Cấp thấp nhất được chọn = kho áp dụng. Nhân sự trong phạm vi kho đó thấy được lead. @endif
                                </p>
                                @error('poolTarget')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror

                                {{-- 2026-08-07: Flip mặc định — mọi lead đều áp thu hồi. Tick ô này = exempt (VD lead đặc biệt CM giữ tay). --}}
                                <label class="mt-3 flex items-start gap-2 text-sm bg-amber-50 border border-amber-200 rounded-md px-3 py-2 cursor-pointer">
                                    <input type="checkbox" wire:model="skipRecall" class="rounded border-amber-300 mt-0.5">
                                    <span>
                                        <span class="font-semibold text-amber-900">Không áp dụng luật thu hồi</span>
                                        <span class="block text-xs text-amber-700 mt-0.5">
                                            Mặc định: sau <b>1 ngày</b> không có ghi nhận cuộc gọi → thu hồi. Sau <b>3 ngày</b> chưa đủ <b>Phân loại + Kết quả + đóng phase 2</b> → thu hồi về kho team. Tick ô này để <b>bỏ qua</b> luật cho lead đặc biệt.
                                        </span>
                                    </span>
                                </label>
                            </div>
                            <div x-data="{ open: false }" @click.outside="open = false" @if ($mktMode === 'pool') style="display:none" @endif>
                                <label class="block text-sm font-medium mb-1.5">NHÂN VIÊN PHỤ TRÁCH</label>
                                @if ($selectedPerson)
                                    <div class="flex items-center justify-between gap-2 border border-gold-300 bg-gold-50 rounded-md px-3 py-2.5">
                                        <span class="text-sm font-semibold text-gold-800">{{ $selectedPerson->name }}</span>
                                        <button type="button" wire:click="clearPerson" class="text-xs font-semibold text-ink/50 hover:text-red-600">Bỏ chọn ✕</button>
                                    </div>
                                    <p class="text-xs text-ink/50 mt-1.5">Lead rời kho chung, chuyển vào kho cá nhân của nhân viên này.</p>
                                @else
                                    <div class="relative">
                                        <input type="text" wire:model.live.debounce.250ms="personSearch" @focus="open = true" placeholder="Gõ tên để tìm nhân sự..."
                                               class="w-full border border-gold-200 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:border-gold-500">
                                        <div x-show="open" x-cloak class="absolute z-20 mt-1 w-full max-h-56 overflow-y-auto bg-white border border-gold-200 rounded-lg shadow-card">
                                            @forelse ($personResults as $u)
                                                <button type="button" wire:click="selectPerson({{ $u->id }})" @click="open = false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gold-50">
                                                    {{ $u->name }}
                                                    <span class="text-xs text-ink/40">{{ $u->email }}</span>
                                                </button>
                                            @empty
                                                <p class="px-3 py-2 text-sm text-ink/40">Không tìm thấy nhân sự phù hợp.</p>
                                            @endforelse
                                        </div>
                                    </div>
                                    @error('personId')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                                @endif
                            </div>
                        </div>
                        <p class="text-xs text-ink/50 mt-4 italic">Chọn xong bấm "Lưu thông tin" ở footer — hệ thống lưu lead + chia số ở phase 1 và chuyển sang phase 3 (Booking thăm khám).</p>
                        @endif {{-- close @if ($canDistribute) inner cascade+person section --}}
                    </div>
                @elseif (! (in_array($sourceGroup, [\App\Models\Lead::SOURCE_MKT, \App\Models\Lead::SOURCE_MKT_BR], true) && ! $lead?->exists))
                    <div class="border-t border-gold-200 pt-4 text-sm text-ink/60 italic">
                        Bạn không có quyền chia số. Người có quyền (CM cơ sở / CM team / Admin) sẽ chia lead này.
                    </div>
                @endif
            </div>

            {{-- ============= Phase 6.21 — Section CALL LOGS (Phase 3) — order 1 (trên cùng) ============= --}}
            <div x-show="phase === 2" x-cloak class="order-1 bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <h2 class="font-bold text-gold-700 mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                    Lịch sử cuộc gọi <span class="text-sm text-ink/50 font-normal">(mỗi lần gọi = 1 record)</span>
                </h2>
                @if ($lead?->exists)
                    @php
                        // Tele đang phụ trách: owner khi phase Booking. Sau khi handoff sang Sale
                        // (phase=sale), tele cũ = closer phase 3.
                        $_tele = $lead->pipeline_phase === \App\Models\Lead::PHASE_BOOKING
                            ? $lead->owner
                            : null;
                        if (! $_tele) {
                            $_teleId = $lead->phaseClosures->firstWhere('phase', 3)?->closed_by;
                            $_tele = $_teleId ? \App\Models\User::find($_teleId) : null;
                        }
                    @endphp
                    <div class="mb-4 p-3 bg-slate-50 border border-gold-200 rounded-md text-sm">
                        <span class="text-xs font-medium text-ink/60">Tele đang phụ trách:</span>
                        <b class="ml-2 {{ $_tele ? 'text-emerald-700' : 'text-ink/40 italic font-normal' }}">{{ $_tele?->name ?? '— chưa phân —' }}</b>
                    </div>
                @endif
                @if ($lead?->exists && $lead->callLogs->isNotEmpty())
                    <div class="border border-slate-200 rounded divide-y mb-4 text-sm">
                        @foreach ($lead->callLogs()->with('user')->orderByDesc('called_at')->get() as $cl)
                            <div class="p-3 flex items-start gap-3">
                                @php
                                    $b = match ($cl->status) {
                                        'thanh_cong' => 'bg-emerald-100 text-emerald-700',
                                        'that_bai' => 'bg-red-100 text-red-700',
                                        default => 'bg-slate-100 text-slate-600',
                                    };
                                @endphp
                                <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap {{ $b }}">{{ $cl->statusLabel() }}</span>
                                <div class="flex-1">
                                    <div class="text-xs text-ink/50">{{ $cl->called_at->format('d/m/Y H:i') }} · {{ $cl->user->name ?? 'system' }}</div>
                                    @if ($cl->note)<div class="text-ink/80 mt-1">{{ $cl->note }}</div>@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                @if ($lead?->exists)
                    <div class="border border-dashed border-slate-300 bg-slate-50 rounded p-3 space-y-2">
                        <div class="text-xs font-semibold text-ink/60">Thêm cuộc gọi mới</div>
                        <div class="grid grid-cols-3 gap-2">
                            <select wire:model="newCallStatus" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
                                @foreach (\App\Models\CallLog::STATUSES as $k => $lbl)
                                    <option value="{{ $k }}">{{ $lbl }}</option>
                                @endforeach
                            </select>
                            <input wire:model="newCallNote" placeholder="Ghi chú cuộc gọi..." class="col-span-2 border border-slate-300 rounded px-2 py-1.5 text-sm">
                        </div>
                        <button type="button" wire:click="addCallLog" wire:loading.attr="disabled" wire:target="addCallLog" class="text-sm bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-1.5 rounded disabled:opacity-50 disabled:cursor-not-allowed">
                            <span wire:loading.remove wire:target="addCallLog">+ Ghi cuộc gọi</span>
                            <span wire:loading wire:target="addCallLog">Đang ghi…</span>
                        </button>
                    </div>
                @else
                    <p class="text-xs text-ink/40 italic">Bấm "Lưu thông tin khách hàng" để tạo lead trước, rồi mới ghi được cuộc gọi.</p>
                @endif
            </div>


            {{-- ============= Phase 6.21 — Section CHECK-IN (Phase 5) đúng field mockup ============= --}}
            <div x-show="phase === 4" x-cloak class="bg-white border border-gold-200 rounded-xl shadow-card p-6">
                <div class="flex items-center justify-between mb-5 gap-3 flex-wrap">
                    <h2 class="font-bold text-gold-700 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                        Check-in <span class="text-sm text-ink/50 font-normal">(tạm thời là bước cuối)</span>
                    </h2>
                    {{-- 2026-08-10 — Sale sau khi tạo booking bị auto-move sang phase 4. Nút này quay về phase 3 để tạo tiếp booking khác cho cùng khách. --}}
                    @if ($lead?->exists && $lead->canRestartBooking(auth()->user()))
                        <button type="button" wire:click="markReturning(3)"
                                onclick="return confirm('Quay lại phase 3 (Booking) để tạo booking mới cho khách này? Lịch sử booking cũ giữ nguyên.')"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-300 hover:bg-amber-200 transition-colors">
                            <span class="text-base leading-none">↩</span> Tạo booking khác cho khách này
                        </button>
                    @endif
                </div>
                {{-- 2026-08-05: Info booking từ BookingLog mới nhất — hiện ở đầu Check-in để lễ tân/admin thấy khách tới lịch nào. --}}
                @if ($lead?->exists)
                    @php
                        $__latestBl = $lead->bookingLogs()->with(['facility', 'consultants'])
                            ->whereIn('status', [\App\Models\BookingLog::STATUS_DA_XAC_NHAN, \App\Models\BookingLog::STATUS_CHO_XAC_NHAN])
                            ->orderByDesc('scheduled_at')->first();
                    @endphp
                    @if ($__latestBl)
                        @php
                            $__sbRoom = $__latestBl->sb_phong_id ? \App\Models\SbRoom::where('sbooking_id', $__latestBl->sb_phong_id)->first() : null;
                            $__sbBs = $__latestBl->sb_bac_si_id ? \App\Models\SbBacSi::where('sbooking_id', $__latestBl->sb_bac_si_id)->first() : null;
                            $__sbSvc = $__latestBl->sb_dich_vu_id ? \App\Models\SbService::where('sbooking_id', $__latestBl->sb_dich_vu_id)->first() : null;
                            $__loaiCls = $__latestBl->type === 'tham_kham' ? 'bg-sky-100 text-sky-700' : 'bg-fuchsia-100 text-fuchsia-700';
                            $__loaiLbl = $__latestBl->type === 'tham_kham' ? '🩺 Thăm khám' : '💆 Dịch vụ';
                        @endphp
                        <div class="mb-4 bg-white border border-blue-200 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                <b class="text-blue-900">📋 Thông tin booking đến lịch</b>
                                <span class="text-xs px-2 py-0.5 rounded {{ $__loaiCls }}">{{ $__loaiLbl }}</span>
                                @if ($__latestBl->sbooking_booking_ma)
                                    <code class="text-xs bg-slate-100 px-1.5 py-0.5 rounded text-slate-700">{{ $__latestBl->sbooking_booking_ma }}</code>
                                @endif
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                                <div><span class="text-ink/50 text-xs">Ngày giờ hẹn:</span> <b>{{ $__latestBl->scheduled_at?->format('d/m/Y H:i') ?? '—' }}</b>@if ($__latestBl->scheduled_end_at) - {{ $__latestBl->scheduled_end_at?->format('H:i') }}@endif</div>
                                <div><span class="text-ink/50 text-xs">Cơ sở:</span> <b>{{ $__latestBl->facility?->name ?? '—' }}</b></div>
                                <div><span class="text-ink/50 text-xs">Phòng:</span> <b>{{ $__sbRoom?->ten ?? '—' }}</b></div>
                                <div><span class="text-ink/50 text-xs">Bác sĩ:</span> <b>{{ $__sbBs?->displayName() ?? '—' }}</b></div>
                                <div class="md:col-span-2"><span class="text-ink/50 text-xs">Dịch vụ:</span> <b>{{ $__sbSvc?->ten ?? '—' }}</b></div>
                                @if ($__latestBl->consultants->isNotEmpty())
                                    <div class="md:col-span-2">
                                        <span class="text-ink/50 text-xs">Chuyên viên tư vấn:</span>
                                        @foreach ($__latestBl->consultants as $__cv)
                                            <span class="text-xs bg-emerald-50 border border-emerald-200 text-emerald-800 px-2 py-0.5 rounded ml-1">#{{ $__cv->pivot->position }} {{ $__cv->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($__latestBl->so_lieu_trinh || $__latestBl->so_luong_lo || $__latestBl->dung_tich_lo)
                                    <div><span class="text-ink/50 text-xs">Liệu trình:</span> <b>{{ $__latestBl->so_lieu_trinh ?: '—' }}</b></div>
                                    <div><span class="text-ink/50 text-xs">Lọ:</span> <b>{{ $__latestBl->so_luong_lo ?: '—' }} @if ($__latestBl->dung_tich_lo) · {{ $__latestBl->dung_tich_lo }} @endif</b></div>
                                @endif
                                @if ($__latestBl->co_tu_van || $__latestBl->co_kham_cls || $__latestBl->ket_hop_medical)
                                    <div class="md:col-span-2 text-xs">
                                        @if ($__latestBl->co_tu_van)<span class="mr-2">✅ Có tư vấn</span>@endif
                                        @if ($__latestBl->co_kham_cls)<span class="mr-2">✅ Khám lâm sàng</span>@endif
                                        @if ($__latestBl->ket_hop_medical)<span class="mr-2">✅ Kết hợp medical</span>@endif
                                    </div>
                                @endif
                                @if ($__latestBl->note)
                                    <div class="md:col-span-2 text-xs bg-slate-50 border border-slate-200 rounded px-2 py-1"><span class="text-ink/50">Ghi chú:</span> {{ $__latestBl->note }}</div>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
                {{-- Phase C1.b rev5 2026-08-01: log tới trễ (nếu có), luôn hiện ở đầu section. --}}
                @if ($lead?->exists)
                    @php $lateLogs = \App\Models\BookingLateLog::where('lead_id', $lead->id)->orderByDesc('created_at')->get(); @endphp
                    @foreach ($lateLogs as $late)
                        <div class="mb-3 p-3 bg-amber-50 border border-amber-200 rounded text-sm text-amber-900">
                            ⚠ <b>Khách tới trễ</b>
                            @if ($late->sbooking_booking_ma) · <span class="font-mono text-xs">{{ $late->sbooking_booking_ma }}</span> @endif
                            @if ($late->late_minutes !== null) · <b>trễ {{ $late->late_minutes }} phút</b> @endif
                            @if ($late->expected_at) · hẹn {{ $late->expected_at->format('d/m/Y H:i') }} @endif
                            @if ($late->arrived_at) · tới {{ $late->arrived_at->format('H:i') }} @endif
                            @if ($late->marked_by) <div class="text-xs text-amber-700 mt-0.5">Ghi bởi: {{ $late->marked_by }}</div> @endif
                        </div>
                    @endforeach
                @endif
                @if ($lead?->exists && $lead->phaseClosures->firstWhere('phase', 5))
                    @php
                        $ci = $lead->phaseClosures->firstWhere('phase', 5);
                        // Phase C1.b rev8 2026-08-01: nếu closure do sbooking auto-close (note bắt đầu "Auto:") → hiển thị "Admin vận hành (sbooking)" thay vì tên user push.
                        $isAutoBySbooking = str_starts_with((string) $ci->note, 'Auto:');
                        $closerLabel = $isAutoBySbooking ? 'Admin vận hành (sbooking)' : (\App\Models\User::find($ci->closed_by)?->name ?? 'system');
                        // Khách tới trễ → box màu amber, không xanh.
                        $isLate = isset($lateLogs) && $lateLogs->isNotEmpty();
                        $boxCls = $isLate
                            ? 'bg-amber-50 border-amber-200 text-amber-800'
                            : 'bg-emerald-50 border-emerald-200 text-emerald-800';
                        $noteCls = $isLate ? 'text-amber-700' : 'text-emerald-700';
                        $icon = $isLate ? '⚠' : '✓';
                    @endphp
                    <div class="p-3 {{ $boxCls }} border rounded text-sm mb-4">
                        {!! $icon !!} Đã check-in lúc {{ $ci->closed_at->format('d/m/Y H:i') }} bởi <b>{{ $closerLabel }}</b>
                        @if ($ci->note)<div class="text-xs {{ $noteCls }} mt-1">{{ $ci->note }}</div>@endif
                    </div>
                @else
                    {{-- 2026-08-05: BỎ 4 field input check-in. Data giờ đồng bộ từ sbooking (BookingEventController::status
                         xử lý 'da_toi' / 'toi_tre' → auto-close phase 5). User không nhập tay nữa. --}}
                    <div class="p-3 bg-sky-50 border border-sky-200 rounded text-sm text-sky-800 mb-4">
                        <div class="font-semibold mb-1">🔄 Check-in đồng bộ tự động từ Sbooking</div>
                        <p class="text-xs text-sky-700">
                            Khi khách tới, Admin BO (sbooking) bấm "Đã tới / Tới trễ" → hệ thống tự close phase 5 ở đây.
                            Không cần nhập tay.
                        </p>
                    </div>
                @endif
            </div>

            {{-- Trường bổ sung — đã chuyển lên dưới "Thông tin khách hàng" (Phase 6.14) --}}
        </fieldset>{{-- close phase-lock fieldset --}}
    </div>
    </fieldset>

    {{-- 2026-08-05: BỎ nút "Khởi động cuộc gọi/đặt lịch mới" (markReturning) — is_first_visit giờ auto:
         chưa có booking hoàn thành = "Đến lần đầu"; có booking da_xong (sbooking push) = "Khách quay lại".
         User không cần thao tác. Xem BookingEventController::status branch. --}}
    @if (false)
    @endif

    {{-- Footer: nhóm "Kết thúc phase / Lùi phase" bên trái để tách khỏi "Lưu thông tin" bên phải, tránh bấm nhầm. --}}
    <div class="border-t border-gold-100 mt-6 pt-5 flex flex-wrap items-center gap-3">
        {{-- LEFT group: phase actions --}}
        <div class="flex flex-wrap gap-3">
            @if ($lead?->exists && ! $isReadonly)
                @php
                    $cfCurPhase = (int) $lead->phase;
                    $cfIsBulk = $lead->isBulkOpen();
                    $cfStart = $lead->startPhase();
                    $cfOpen = $lead->openFrom();
                    $cfCanRollback = auth()->user()->hasPermission(\App\Models\Lead::CF_ROLLBACK_PERM);
                    $cfClosuresMap = $lead->phaseClosures->keyBy('phase');
                @endphp
                {{-- 2026-08-05: phase 4 (Check-in) chỉ cho Admin/Lễ tân có phase.close.checkin (hoặc rollback) đóng.
                     Trực page + Sale không thao tác — sbooking tự auto-close khi khách tới (da_toi/toi_tre callback).
                     2026-08-09: guard chung theo perm phase.close.<slug> — Trực Page chỉ có
                     phase.close.new (=phase 1), không thấy nút ở phase 2/3/4. --}}
                @php
                    $__closePerm = \App\Models\Lead::CF_PHASE_CLOSE_PERM[$activePhase] ?? null;
                    $cfCanCloseCurrent = $cfCanRollback
                        || ($__closePerm && auth()->user()->hasPermission($__closePerm));
                @endphp
                @if ($cfIsBulk && $activePhase >= $cfOpen && $activePhase <= $cfStart)
                    @unless ($cfClosuresMap->has($activePhase))
                        @if ($cfCanCloseCurrent)
                            <button type="button" wire:click="closePhaseNow({{ $activePhase }})"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm px-5 py-2.5 rounded-md">
                                Kết thúc phase {{ $activePhase }} (riêng)
                            </button>
                        @endif
                    @endunless
                    <button type="button" wire:click="bulkSavePhases"
                            class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm px-5 py-2.5 rounded-md">
                        Lưu chốt {{ $cfStart - $cfOpen + 1 }} phase ({{ $cfOpen }}→{{ $cfStart }})
                    </button>
                @elseif (! $cfIsBulk && $activePhase === $cfCurPhase && $activePhase <= 4 && $cfCanCloseCurrent)
                    <button type="button" wire:click="closePhaseNow({{ $activePhase }})"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm px-5 py-2.5 rounded-md">
                        Kết thúc phase {{ $activePhase }}
                    </button>
                @endif
                @if ($cfCanRollback && $activePhase < $cfCurPhase && $cfClosuresMap->has($activePhase))
                    <button type="button" wire:click="rollbackToPhase({{ $activePhase }})"
                            onclick="return confirm('Lùi phase về {{ $activePhase }}? Xóa closure từ phase này trở đi.')"
                            class="bg-white border border-red-400 text-red-600 hover:bg-red-50 font-semibold text-sm px-5 py-2.5 rounded-md">
                        ⤺ Lùi phase {{ $activePhase }} (Admin)
                    </button>
                @endif
            @endif
        </div>

        {{-- RIGHT group: Hủy + Lưu (đẩy sang phải bằng ml-auto) --}}
        <div class="flex flex-wrap gap-3 ml-auto">
            <a href="{{ $lead ? route('leads.show', $lead) : (auth()->user()->hasPermission('lead.view') ? route('leads.index') : route('dashboard')) }}" class="text-sm font-semibold text-ink/60 border border-gold-200 px-5 py-2.5 rounded-md hover:bg-gold-50">Hủy</a>
            {{-- 2026-08-05: dùng $canWrite thay $isReadonly — Sale owner không có perm sửa info → ẩn nút (khỏi bấm ăn 403). --}}
            @if ($canWrite)
                <button wire:click="saveAndGoToBooking" class="bg-gold-600 hover:bg-gold-700 text-white font-semibold text-sm px-6 py-2.5 rounded-md">Lưu thông tin khách hàng</button>
            @endif
        </div>
    </div>

    {{-- Sidebar comment fixed cũ đã move lên header 50/50 — 2026-08-02. --}}
</div>

