{{-- Payments: money in to check, money owed back, COD the courier owes. @include with ['active' => 'check'|'refunds'|'cod'] --}}
@php($owedN = \Illuminate\Support\Facades\DB::table('orders')->where('refund_due', '>', 0)->count())
<x-tabs :tabs="[
    'check' => [__('Payments to check'), route('payments.index')],
    'refunds' => [__('Refunds'), route('refunds.index'), $owedN ?: null],
    'cod' => [__('COD from courier'), route('cod.index')],
]" :active="$active" />
