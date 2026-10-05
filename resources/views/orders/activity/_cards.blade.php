{{-- Next cards of one column ("Load more"). --}}
@foreach ($cards as $o)
    @include('orders.activity._card', ['o' => $o])
@endforeach
@if ($more)
    <button type="button" data-more="{{ $column }}" data-page="{{ $page + 1 }}" class="w-full rounded-lg border border-dashed border-gray-300 py-2 text-xs font-medium text-gray-600 hover:border-primary hover:text-primary">{{ __('Load more') }}</button>
@endif
