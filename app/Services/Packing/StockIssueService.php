<?php

namespace App\Services\Packing;

use App\Models\Order;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\NotificationService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\DB;

/**
 * Packer says "item not found on the shelf": a report to Admin only. The
 * order is flagged and skipped in packing; Admin decides Out of stock /
 * Pre-order (orders then move to Hold automatically) or dismisses it.
 */
class StockIssueService
{
    public function __construct(private NotificationService $notifications, private OrderService $orders, private ProductService $products) {}

    public function report(int $variantId, ?int $orderId, ?int $batchId, ?User $by, ?string $note = null): int
    {
        $id = DB::table('stock_issue_reports')->insertGetId([
            'order_id' => $orderId, 'batch_id' => $batchId, 'variant_id' => $variantId, 'reported_by' => $by?->id,
            'note' => $note, 'status' => 'open', 'created_at' => now(),
        ]);

        $orderIds = $orderId ? [$orderId] : ($batchId
            ? DB::table('orders as o')->join('order_items as i', 'i.order_id', '=', 'o.id')->where('o.batch_id', $batchId)->where('i.variant_id', $variantId)->pluck('o.id')->all()
            : []);
        DB::table('orders')->whereIn('id', $orderIds)->update(['stock_issue_flag' => true]);
        foreach (Order::whereIn('id', $orderIds)->get() as $order) {
            $this->orders->note($order, 'system', __('Packer could not find an item. Skipped in packing until Admin decides.'), $by);
        }

        $row = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->where('v.id', $variantId)
            ->first(['p.name as product', 'v.name as variant']);
        $name = $row ? $row->product.($row->variant !== 'Default' ? ' · '.$row->variant : '') : null;
        $this->notifications->send('stock_issue_reported', __('Item not found: :i', ['i' => $name ?? '#'.$variantId]),
            trim(($by ? __('Reported by :n', ['n' => $by->name]) : '').' '.($note ?? '')), [
                'link' => route('packing.issues'), 'subject' => ['stock_issue', $id], 'priority' => 'urgent',
            ]);

        return $id;
    }

    /** @param string $decision out_of_stock | backorder | dismiss */
    public function resolve(int $reportId, string $decision, User $by, ?string $expectedDate = null): void
    {
        $report = DB::table('stock_issue_reports')->where('id', $reportId)->where('status', 'open')->first();
        abort_unless($report, 404);

        DB::transaction(function () use ($report, $decision, $by, $expectedDate) {
            if ($decision !== 'dismiss') {
                $this->products->setAvailability([$report->variant_id], $decision, $by->id, $expectedDate, __('From packer report'));
            }
            DB::table('stock_issue_reports')->where('id', $report->id)->update([
                'status' => ['out_of_stock' => 'marked_out_of_stock', 'backorder' => 'marked_pre_order', 'dismiss' => 'dismissed'][$decision],
                'resolved_by' => $by->id, 'resolved_at' => now(),
            ]);

            // Flag stays only while another open report still covers the order.
            $orderIds = DB::table('orders')->where('stock_issue_flag', true)
                ->whereIn('id', DB::table('order_items')->select('order_id')->where('variant_id', $report->variant_id))->pluck('id');
            foreach ($orderIds as $orderId) {
                $stillOpen = DB::table('stock_issue_reports as r')->join('order_items as i', 'i.variant_id', '=', 'r.variant_id')
                    ->where('i.order_id', $orderId)->where('r.status', 'open')->exists();
                if (! $stillOpen) {
                    DB::table('orders')->where('id', $orderId)->update(['stock_issue_flag' => false]);
                }
            }
            $this->notifications->markActed('stock_issue', $report->id);
        });
    }
}
