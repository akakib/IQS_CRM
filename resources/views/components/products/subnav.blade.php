@props(['active'])

@php
    $tabs = ['products' => [__('Products'), route('products.index')]];
    if (auth()->user()->can('products.availability') && Route::has('products.availability')) {
        $tabs['availability'] = [__('Stock status'), route('products.availability')];
    }
    if (Route::has('products.stock')) {
        $tabs['stock'] = [__('Stock count'), route('products.stock')];
    }
    if (auth()->user()->can('products.edit') && Route::has('categories.index')) {
        $tabs['categories'] = [__('Categories'), route('categories.index')];
    }
    if (auth()->user()->can('products.create') && Route::has('products.import')) {
        $tabs['import'] = [__('Import from website'), route('products.import')];
    }
@endphp

<x-tabs :tabs="$tabs" :active="$active" />
