<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Old journey links. The journey is now a tab of the customer page (CustomerJourney builds it) and its
 * search is the one search; these routes only send old links there.
 */
class CustomerJourneyController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('orders.search', array_filter(['q' => $request->query('q')]));
    }

    public function show(Customer $customer): RedirectResponse
    {
        // A merged customer leads to the one they were merged into.
        for ($hop = 0; $customer->merged_into_id && $hop < 10; $hop++) {
            $customer = Customer::withTrashed()->findOrFail($customer->merged_into_id);
        }
        abort_if($customer->trashed(), 404);

        return redirect()->to(route('customers.show', $customer).'#journey');
    }
}
