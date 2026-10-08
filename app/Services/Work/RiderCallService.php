<?php

namespace App\Services\Work;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Orders\DeliveryIssueService;
use App\Services\Orders\OrderService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A rider calls the hotline about a parcel: find it by CN, order number or
 * phone, note what the rider says, call the customer to check it, and say
 * what was done. Kept per rider so the Riders report shows who calls how
 * often and how often their reason turned out not to be true.
 */
class RiderCallService
{
    public const CLAIMS = [
        'no_answer' => 'Customer not answering', 'address' => 'Address wrong or not found', 'refused' => 'Customer refuses',
        'later' => 'Customer wants it later', 'cod' => 'COD amount dispute', 'other' => 'Other',
    ];

    public const VERDICTS = ['true' => 'True', 'false' => 'Not true', 'unclear' => 'Could not tell'];

    public const ACTIONS = [
        'solved' => 'Solved on the call', 'retry' => 'Rider tries again', 'rescheduled' => 'New delivery date',
        'moderator' => 'Sent to the person on the order', 'cancel' => 'Cancel or return',
    ];

    /** Delivery issue type for a claim sent on to the person on the order. */
    private const ISSUE_TYPE = ['no_answer' => 'no_answer', 'address' => 'address', 'refused' => 'cancel', 'later' => 'hold', 'cod' => 'other', 'other' => 'other'];

    public function __construct(private OrderService $orders, private DeliveryIssueService $issues, private ChatService $chat) {}

    /** The parcel for a CN, an order number or the customer's phone, with what the courier last said. */
    public function find(string $q): ?array
    {
        $q = trim($q);
        $digits = preg_replace('/\D/', '', strtr($q, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));
        $phone = Phone::normalize($q);
        $order = Order::query()
            ->where(fn ($w) => $w->where('order_no', strtoupper($q))
                ->when(strlen($digits) >= 5, fn ($w) => $w->orWhereIn('id', DB::table('shipments')->select('order_id')->where('consignment_id', $digits)))
                ->when($phone, fn ($w) => $w->orWhere('ship_phone', $phone)))
            ->orderByDesc('id')->first();
        if (! $order) {
            return null;
        }
        $shipment = $order->active_shipment_id ? DB::table('shipments')->where('id', $order->active_shipment_id)->first(['consignment_id', 'courier_status']) : null;

        return [
            'id' => $order->id, 'order_no' => $order->order_no, 'customer' => $order->ship_name, 'phone' => $order->ship_phone,
            'address' => trim(implode(', ', array_filter([$order->ship_address, $order->ship_thana, $order->ship_district]))),
            'cod' => (float) $order->cod_amount, 'status' => OrderStatus::map()[$order->status_id]['name'] ?? '',
            'cn' => $shipment->consignment_id ?? null, 'courier_status' => $shipment->courier_status ?? null,
            'courier_note' => DB::table('order_notes')->where('order_id', $order->id)->where('note_type', 'courier')->orderByDesc('id')->value('body'),
            'moderator' => $order->moderator_id ? DB::table('users')->where('id', $order->moderator_id)->value('name') : null,
            'earlier_calls' => DB::table('rider_calls')->where('order_id', $order->id)->count(),
            'open_issues' => DB::table('delivery_issues')->where('order_id', $order->id)->whereNull('resolved_at')->count(),
        ];
    }

    /** @param array{order_id: int, rider_name?: ?string, rider_phone?: ?string, claim: string, verdict?: ?string, action?: ?string, note?: ?string} $d */
    public function record(User $by, array $d): int
    {
        $order = Order::find($d['order_id']) ?? throw ValidationException::withMessages(['order_id' => __('Find the parcel first.')]);
        foreach (['claim' => self::CLAIMS, 'verdict' => self::VERDICTS, 'action' => self::ACTIONS] as $field => $allowed) {
            if (($d[$field] ?? null) !== null && ! isset($allowed[$d[$field]])) {
                throw ValidationException::withMessages([$field => __('Choose from the list.')]);
            }
        }
        if (empty($d['verdict']) || empty($d['action'])) {
            throw ValidationException::withMessages([empty($d['verdict']) ? 'verdict' : 'action' => __('Say what the customer said and what was done.')]);
        }

        // The rider: a known name, or a new one remembered for next time (as in Handover).
        $riderId = null;
        $name = trim((string) ($d['rider_name'] ?? ''));
        $phone = Phone::normalize($d['rider_phone'] ?? null);
        if ($name !== '') {
            $rider = DB::table('riders')->where('name', $name)->first();
            $riderId = $rider ? $rider->id : DB::table('riders')->insertGetId(['name' => $name, 'phone' => $phone, 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('riders')->where('id', $riderId)->update(array_filter(['phone' => $phone ?: null, 'last_used_at' => now(), 'updated_at' => now()]));
            $phone = $phone ?: ($rider->phone ?? null);
        }

        $issueId = $d['action'] === 'moderator'
            ? $this->issues->open($order, self::ISSUE_TYPE[$d['claim']], $phone, trim(__(self::CLAIMS[$d['claim']]).'. '.($d['note'] ?? '')), $by)
            : null;
        $id = DB::table('rider_calls')->insertGetId([
            'order_id' => $order->id, 'consignment_id' => DB::table('shipments')->where('id', $order->active_shipment_id)->value('consignment_id'),
            'rider_id' => $riderId, 'rider_phone' => $phone, 'claim' => $d['claim'], 'verdict' => $d['verdict'], 'action' => $d['action'],
            'note' => $d['note'] ?? null, 'delivery_issue_id' => $issueId, 'handled_by' => $by->id, 'created_at' => now(),
        ]);
        $this->orders->note($order, 'rider', __('Rider call:rider: :c · checked with the customer: :v · :a', [
            'rider' => $name !== '' ? ' '.$name : '', 'c' => __(self::CLAIMS[$d['claim']]), 'v' => __(self::VERDICTS[$d['verdict']]), 'a' => __(self::ACTIONS[$d['action']]),
        ]).(! empty($d['note']) ? ' · '.$d['note'] : ''), $by, ['rider_call_id' => $id]);
        // Hotline work counts as work, like chats.
        $this->chat->start($by);

        return $id;
    }
}
