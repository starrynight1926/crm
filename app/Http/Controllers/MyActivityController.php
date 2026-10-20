<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadDistributionLog;
use App\Models\LeadStatusLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lịch sử hoạt động của user (self) + admin xem của user khác.
 *
 * Gom 2 nguồn: lead_status_logs (tạo/sửa/cập nhật lead) +
 * lead_distribution_logs (chia/thu hồi/pull/manual_assign).
 * Không double-write; format lại thành dòng dễ đọc.
 */
class MyActivityController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request)
    {
        $me = auth()->user();
        $targetId = (int) $request->integer('user_id') ?: $me->id;

        if ($targetId !== $me->id && ! $me->hasPermission('user.manage')) {
            abort(403);
        }

        $target = User::find($targetId);
        abort_unless($target, 404);

        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : null;

        // Đếm tổng để paginate — dùng subquery UNION.
        $statusQ = LeadStatusLog::query()
            ->where('user_id', $targetId)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw("id, lead_id, user_id, created_at, 'status' as src");

        $distQ = LeadDistributionLog::query()
            ->where('actor_id', $targetId)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw("id, lead_id, actor_id as user_id, created_at, 'dist' as src");

        $union = $statusQ->unionAll($distQ);
        $total = \DB::query()->fromSub($union, 't')->count();

        $page = max(1, (int) $request->integer('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = \DB::query()->fromSub($union, 't')
            ->orderByDesc('created_at')
            ->offset($offset)->limit(self::PER_PAGE)->get();

        // Eager-load models theo id để hiển thị.
        $statusIds = $rows->where('src', 'status')->pluck('id')->all();
        $distIds = $rows->where('src', 'dist')->pluck('id')->all();
        $statusLogs = LeadStatusLog::whereIn('id', $statusIds)->get()->keyBy('id');
        $distLogs = LeadDistributionLog::whereIn('id', $distIds)->with(['toOwner:id,name'])->get()->keyBy('id');

        $leadIds = $rows->pluck('lead_id')->unique()->filter()->all();
        $leads = Lead::whereIn('id', $leadIds)->get(['id', 'code', 'name'])->keyBy('id');

        $items = $rows->map(function ($r) use ($statusLogs, $distLogs, $leads) {
            $lead = $leads->get($r->lead_id);
            $leadLabel = $lead ? trim(($lead->code ?: ('KH-'.str_pad((string) $lead->id, 3, '0', STR_PAD_LEFT))).' '.$lead->name) : "Lead #{$r->lead_id}";
            $ts = Carbon::parse($r->created_at);

            if ($r->src === 'status') {
                $log = $statusLogs->get($r->id);
                $text = $this->formatStatus($log, $leadLabel);
            } else {
                $log = $distLogs->get($r->id);
                $text = $this->formatDist($log, $leadLabel);
            }

            return (object) [
                'at' => $ts,
                'lead_id' => $r->lead_id,
                'text' => $text,
            ];
        });

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Nhóm theo ngày
        $groups = $paginator->getCollection()->groupBy(fn ($x) => $x->at->toDateString());

        return view('me.activity', [
            'target' => $target,
            'isSelf' => $target->id === $me->id,
            'canPickUser' => $me->hasPermission('user.manage'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'paginator' => $paginator,
            'groups' => $groups,
        ]);
    }

    private function formatStatus(?LeadStatusLog $log, string $leadLabel): string
    {
        if (! $log) {
            return 'Cập nhật '.$leadLabel;
        }
        $field = $log->field;
        $label = LeadStatusLog::FIELD_LABELS[$field] ?? $field;

        if ($field === 'created') {
            return 'Thêm lead mới '.$leadLabel.($log->new_value ? ' ('.$log->new_value.')' : '');
        }

        $old = trim((string) $log->old_value);
        $new = trim((string) $log->new_value);

        if ($old !== '' && $new !== '' && $old !== $new) {
            return 'Cập nhật '.$leadLabel.': '.$label.' '.$old.' → '.$new;
        }
        if ($new !== '') {
            return 'Cập nhật '.$leadLabel.': '.$label.' — '.\Illuminate\Support\Str::limit($new, 120);
        }
        return 'Cập nhật '.$leadLabel.': '.$label;
    }

    private function formatDist(?LeadDistributionLog $log, string $leadLabel): string
    {
        if (! $log) {
            return 'Thao tác lead '.$leadLabel;
        }
        $verbMap = [
            LeadDistributionLog::ACTION_DISTRIBUTE => 'Chia',
            LeadDistributionLog::ACTION_RECALL => 'Thu hồi',
            LeadDistributionLog::ACTION_PULL => 'Kéo',
            LeadDistributionLog::ACTION_MANUAL => 'Gán tay',
            LeadDistributionLog::ACTION_ESCALATE => 'Escalate',
            LeadDistributionLog::ACTION_APPROVE => 'Duyệt',
            LeadDistributionLog::ACTION_REJECT => 'Từ chối',
            LeadDistributionLog::ACTION_MARK_OVERDUE => 'Đánh dấu quá hạn',
        ];
        $verb = $verbMap[$log->action] ?? $log->action;
        $target = $log->toOwner?->name;
        $tail = $target ? ' → '.$target : '';
        $reason = $log->reason ? ' ('.\Illuminate\Support\Str::limit($log->reason, 80).')' : '';

        return $verb.' '.$leadLabel.$tail.$reason;
    }
}
