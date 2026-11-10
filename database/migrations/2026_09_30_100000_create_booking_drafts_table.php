<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng draft cho trang /simple-booking — nháp lịch đặt kiểu Excel, ai trong scope
 * cơ sở cũng xem/sửa được. Không dính hook BookingLog (transfer owner, sync sbooking,
 * đẩy phase…) — chỉ là sheet chia sẻ. Có thể "promote" thành BookingLog thật sau.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_drafts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id')->nullable()->index();
            $t->date('ngay_dat_lich')->nullable();
            $t->time('gio')->nullable();
            $t->string('nguon', 64)->nullable();
            $t->string('ho_ten', 191)->nullable();
            $t->string('sdt', 32)->nullable()->index();
            $t->string('sale', 191)->nullable();
            $t->string('lieu_phap', 191)->nullable();
            $t->string('so_lo', 64)->nullable();
            $t->string('dieu_duong', 191)->nullable();
            $t->string('bac_si', 191)->nullable();
            $t->text('ghi_chu')->nullable();
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('promoted_booking_log_id')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_drafts');
    }
};
