<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\NotificationService;
use App\Services\Orders\OrderService;
use App\Support\Phone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer complaints: opened against an order (or just a phone number),
 * assigned to the order's moderator by default, with an SLA that escalates to
 * managers. Resolving names the stage to blame (sales / packing / courier …)
 * so quality can be traced; refunds are a separate approval flow.
 * Every change is a complaint_events row: nothing is edited in place.
 */
class ComplaintService
{
    public function __construct(
        private OrderService $orders,
        private CustomerService $customers,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array{order_id?: int|null, customer_phone?: string|null, customer_name?: string|null, category_id: int, source: string, description: string, assigned_to?: int|null}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function open(array $data, array $photos, ?User $by): Complaint
    {
        $order = ! empty($data['order_id']) ? Order::find($data['order_id']) : null;
        $phone = Phone::normalize($data['customer_phone'] ?? $order?->ship_phone);
        if (! $order && ! $phone) {
            throw ValidationException::withMessages(['customer_phone' => __('Give the order number or the customer phone.')]);
        }
        $category = DB::table('status_reasons')->where('id', $data['category_id'])->where('reason_type', 'complaint')->where('is_active', true)->first();
        if (! $category) {
            throw ValidationException::withMessages(['category_id' => __('Choose the complaint type.')]);
        }
        if (! in_array($data['source'], Complaint::SOURCES, true)) {
            throw ValidationException::withMessages(['source' => __('Where did the complaint come from?')]);
        }

        $customer = $order?->customer ?? $this->customers->findByPhone($phone);

        return DB::transaction(function () use ($data, $photos, $by, $order, $phone, $category, $customer) {
            $complaint = Complaint::create([
                'order_id' => $order?->id,
                'customer_id' => $customer?->id,
                'customer_name' => trim((string) ($data['customer_name'] ?? '')) ?: ($order?->ship_name ?? $customer?->name ?? __('Unknown')),
                'customer_phone' => $phone ?? $order->ship_phone,
                'category_id' => $category->id,
                'source' => $data['source'],
                'description' => trim($data['description']),
                'blame_stage' => $category->blame_stage,
                'assigned_to' => $data['assigned_to'] ?? $order?->moderator_id ?? $by?->id,
                'opened_by' => $by?->id,
                'status' => 'open',
                'sla_due_at' => now()->addHours((int) settings('complaints.sla_hours')),
            ]);
            $this->event($complaint, 'opened', __(':c · :d', ['c' => $category->label_en, 'd' => $complaint->description]), $by);
            $this->addPhotos($complaint, $photos, $by, false);

            if ($order) {
                $this->orders->note($order, 'manual', __('Complaint #:id opened: :c', ['id' => $complaint->id, 'c' => $category->label_en]), $by, ['complaint_id' => $complaint->id]);
            }
            $this->notifications->send('complaint_opened', __('Complaint #:id: :c', ['id' => $complaint->id, 'c' => $category->label_en]),
                ($order ? $order->order_no.' · ' : '').$complaint->customer_name, [
                    'link' => route('complaints.show', $complaint), 'subject' => ['complaint', $complaint->id],
                    'order_moderator_id' => $complaint->assigned_to, 'group_key' => 'complaint:'.$complaint->id,
                ]);

            return $complaint;
        });
    }

    /** @param  list<UploadedFile>  $photos */
    public function addPhotos(Complaint $complaint, array $photos, ?User $by, bool $logEvent = true): int
    {
        $n = 0;
        foreach ($photos as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $path = $file->store('complaints/'.$complaint->id, 'local');
            DB::table('complaint_photos')->insert([
                'complaint_id' => $complaint->id, 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 150),
                'size_bytes' => (int) $file->getSize(), 'uploaded_by' => $by?->id, 'created_at' => now(),
            ]);
            $n++;
        }
        if ($n && $logEvent) {
            $this->event($complaint, 'photo_added', __(':n photo(s) added', ['n' => $n]), $by);
        }

        return $n;
    }

    public function note(Complaint $complaint, string $body, ?User $by): void
    {
        $this->event($complaint, 'note', trim($body), $by);
    }

    public function assign(Complaint $complaint, User $to, User $by): void
    {
        $this->guardOpen($complaint);
        $complaint->update(['assigned_to' => $to->id]);
        $this->event($complaint, 'assigned', __('Given to :n', ['n' => $to->name]), $by);
        $this->notifications->send('complaint_opened', __('Complaint #:id given to you', ['id' => $complaint->id]), $complaint->customer_name, [
            'link' => route('complaints.show', $complaint), 'subject' => ['complaint', $complaint->id], 'user_ids' => [$to->id],
        ]);
    }

    /**
     * Close the complaint naming how it ended and whose stage caused it. The
     * blamed person defaults to whoever worked that stage on the order.
     */
    public function resolve(Complaint $complaint, string $resolution, string $stage, ?int $blamedUserId, ?string $note, User $by): void
    {
        $this->guardOpen($complaint);
        if (! in_array($resolution, Complaint::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => __('Choose how it ended.')]);
        }
        if (! in_array($stage, Complaint::STAGES, true)) {
            throw ValidationException::withMessages(['blame_stage' => __('Choose the stage at fault.')]);
        }
        if ($resolution === 'refunded' && ! DB::table('refunds')->where('complaint_id', $complaint->id)->whereIn('status', ['approved', 'paid'])->exists()) {
            throw ValidationException::withMessages(['resolution' => __('Request the refund first; "Refunded" needs an approved refund on this complaint.')]);
        }

        $order = $complaint->order_id ? Order::find($complaint->order_id) : null;
        $blamed = $blamedUserId ?? match ($stage) {
            'sales', 'verification' => $order?->moderator_id,
            'packing', 'dispatch' => $order?->packer_id,
            default => null,
        };

        DB::transaction(function () use ($complaint, $resolution, $stage, $blamed, $note, $by, $order) {
            $complaint->update([
                'status' => 'resolved', 'resolution' => $resolution, 'resolution_note' => $note, 'blame_stage' => $stage,
                'blamed_user_id' => $blamed, 'resolved_by' => $by->id, 'resolved_at' => now(),
            ]);
            $who = $blamed ? DB::table('users')->where('id', $blamed)->value('name') : null;
            $this->event($complaint, 'resolved', __('Resolved: :r · fault: :s:w:n', [
                'r' => __(ucfirst($resolution)), 's' => __(ucfirst($stage)), 'w' => $who ? ' ('.$who.')' : '', 'n' => $note ? ' · '.$note : '',
            ]), $by);
            if ($order) {
                $this->orders->note($order, 'manual', __('Complaint #:id resolved: :r', ['id' => $complaint->id, 'r' => $resolution]), $by, ['complaint_id' => $complaint->id]);
            }
            $this->notifications->markActed('complaint', $complaint->id);
        });
    }

    public function reopen(Complaint $complaint, ?string $why, User $by): void
    {
        abort_unless($complaint->status === 'resolved', 422);
        $complaint->update([
            'status' => 'open', 'resolution' => null, 'resolution_note' => null, 'resolved_by' => null, 'resolved_at' => null,
            'sla_due_at' => now()->addHours((int) settings('complaints.sla_hours')), 'escalated_at' => null,
        ]);
        $this->event($complaint, 'reopened', $why, $by);
    }

    /** Open complaints past their SLA go to managers once. @return int how many escalated */
    public function escalate(): int
    {
        $due = DB::table('complaints as c')->join('status_reasons as r', 'r.id', '=', 'c.category_id')
            ->where('c.status', 'open')->whereNull('c.escalated_at')->where('c.sla_due_at', '<=', now())
            ->get(['c.id', 'c.customer_name', 'r.label_en']);

        $managers = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->whereIn('r.system_key', ['manager', 'owner'])->whereNull('r.deleted_at')->distinct()->pluck('ur.user_id')->all();

        foreach ($due as $c) {
            DB::table('complaints')->where('id', $c->id)->update(['escalated_at' => now(), 'updated_at' => now()]);
            DB::table('complaint_events')->insert(['complaint_id' => $c->id, 'action' => 'escalated', 'body' => __('Not resolved in time: sent to managers.'), 'created_at' => now()]);
            $this->notifications->send('complaint_overdue', __('Overdue complaint #:id: :c', ['id' => $c->id, 'c' => $c->label_en]), $c->customer_name, [
                'link' => route('complaints.show', $c->id), 'subject' => ['complaint', $c->id], 'user_ids' => $managers, 'priority' => 'urgent',
            ]);
        }

        return $due->count();
    }

    public function event(Complaint $complaint, string $action, ?string $body, ?User $by): void
    {
        DB::table('complaint_events')->insert([
            'complaint_id' => $complaint->id, 'action' => $action, 'body' => $body, 'user_id' => $by?->id, 'created_at' => now(),
        ]);
        $complaint->touch();
    }

    private function guardOpen(Complaint $complaint): void
    {
        if ($complaint->status !== 'open') {
            throw ValidationException::withMessages(['status' => __('This complaint is already resolved. Reopen it first.')]);
        }
    }
}
