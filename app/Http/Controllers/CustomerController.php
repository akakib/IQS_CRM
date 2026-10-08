<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Services\Customers\CustomerService;
use App\Services\Customers\FraudCheckService;
use App\Support\Lists\ListState;
use App\Support\Permissions\Mask;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function index(Request $request): View
    {
        $list = ListState::from($request, ['created_at', 'name', 'orders_count'], ['risk' => ['normal', 'watch', 'blocked']], 'desc');
        $digits = preg_replace('/\D/', '', $list->search);

        // A number search hits the unique phone index; a name search is a prefix match.
        $customers = Customer::query()
            ->select(['id', 'name', 'primary_phone', 'risk_level', 'orders_count', 'delivered_count', 'returned_count', 'created_at'])
            ->when($list->search !== '' && strlen($digits) >= 4, fn ($q) => $q->whereIn('id', DB::table('customer_phones')->select('customer_id')
                ->where('phone', 'like', (Phone::normalize($digits) ?? (str_starts_with($digits, '0') ? $digits : '0'.ltrim($digits, '0'))).'%')))
            ->when($list->search !== '' && strlen($digits) < 4, fn ($q) => $q->where('name', 'like', $list->search.'%'))
            ->when($list->filter('risk'), fn ($q, $r) => $q->where('risk_level', $r))
            ->tap(fn ($q) => $list->applySort($q))
            ->paginate($list->perPage)
            ->withQueryString();

        return view('customers.index', ['customers' => $customers, 'list' => $list]);
    }

    public function create(): View
    {
        return view('customers.create', $this->formData(new Customer(['risk_level' => 'normal'])));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $customer = $this->customers->save(new Customer, $data, $data['extra_phones'] ?? [], $data['addresses'] ?? [], $request->user()->id);

        return redirect()->route('customers.show', $customer)->with('success', __('Customer added.'));
    }

    public function show(Customer $customer, Request $request): View
    {
        $customer->load(['phones', 'addresses.zone:id,name']);
        $checks = $customer->fraudChecks()->with('provider:id,name,system_key')->latest('checked_at')->limit(20)->get();

        return view('customers.show', [
            'customer' => $customer,
            'checks' => $checks,
            'latest' => $checks->unique('provider_id'),
            'canSeeContact' => $request->user()->canSeeField('customer_contact'),
        ]);
    }

    public function edit(Customer $customer, Request $request): View
    {
        // Editing shows every number and address, so a role that hides contact details cannot edit.
        abort_unless($request->user()->canSeeField('customer_contact'), 403);
        return view('customers.edit', $this->formData($customer->load(['phones', 'addresses'])));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()->canSeeField('customer_contact'), 403);
        $data = $this->validated($request);
        $this->customers->save($customer, $data, $data['extra_phones'] ?? [], $data['addresses'] ?? [], $request->user()->id);

        return redirect()->route('customers.show', $customer)->with('success', __('Customer saved.'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return redirect()->route('customers.index')->with('success', __('Customer deleted.'));
    }

    public function merge(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['duplicate_phone' => ['required', 'string']]);
        $duplicate = $this->customers->findByPhone($data['duplicate_phone']);

        if (! $duplicate || $duplicate->is($customer)) {
            return back()->with('error', __('No other customer has that number.'));
        }

        $this->customers->merge($customer, $duplicate, $request->user()->id);

        return back()->with('success', __(':name merged into this customer.', ['name' => $duplicate->name]));
    }

    public function fraudCheck(Customer $customer, FraudCheckService $fraud): RedirectResponse
    {
        $fraud->check($customer, force: true);

        return back()->with('success', __('Delivery history checked.'));
    }

    /** Order entry: who is this number? One indexed query + addresses. */
    public function lookup(Request $request): JsonResponse
    {
        $customer = $this->customers->findByPhone($request->query('phone'));
        if (! $customer) {
            return response()->json(['found' => false, 'valid' => Phone::isValid($request->query('phone'))]);
        }
        $customer->load('addresses');

        return response()->json([
            'found' => true,
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => Mask::value($customer->primary_phone, 'customer_contact'),
            'risk_level' => $customer->risk_level,
            'orders' => $customer->orders_count,
            'delivered' => $customer->delivered_count,
            'returned' => $customer->returned_count,
            'addresses' => $request->user()->canSeeField('customer_contact')
                ? $customer->addresses->map(fn ($a) => ['id' => $a->id, 'line' => $a->oneLine(), 'zone_id' => $a->zone_id, 'is_default' => $a->is_default])
                : [],
        ]);
    }

    /**
     * The Steadfast record for a phone typed in the order form, loaded after the name and address so those
     * are never slowed down. A known customer uses the saved check (24 hours); a new number is kept as long.
     */
    public function steadfast(Request $request): JsonResponse
    {
        $phone = Phone::normalize($request->query('phone'));
        if (! $phone) {
            return response()->json(['found' => false]);
        }
        $customer = $this->customers->findByPhone($phone);
        try {
            if ($customer) {
                $check = app(\App\Services\Customers\FraudCheckService::class)->check($customer)->get('steadfast');
                $r = $check ? ['parcels' => (int) $check->total_parcels, 'rate' => $check->success_rate, 'raw' => (array) $check->raw_response] : null;
            } else {
                $r = \Illuminate\Support\Facades\Cache::remember('steadfast:phone:'.$phone, now()->addHours(24), function () use ($phone) {
                    $x = app(\App\Services\Courier\CourierManager::class)->scoreDriver()->fraudCheck($phone);

                    return ['parcels' => $x->totalParcels, 'rate' => $x->successRate, 'raw' => $x->raw];
                });
            }
        } catch (\Throwable $e) {
            return response()->json(['found' => false, 'error' => __('Steadfast did not answer.')]);
        }
        if (! $r) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'parcels' => $r['raw']['volume_range'] ?? $r['parcels'],
            'has_history' => $r['parcels'] > 0 || $r['rate'] !== null,
            'delivered' => $r['rate'] === null ? null : (float) $r['rate'] + 0,
            'cancelled' => isset($r['raw']['cancellation_ratio']) ? (float) $r['raw']['cancellation_ratio'] + 0 : null,
        ]);
    }

    private function validated(Request $request): array
    {
        $request->merge(['marketing_consent' => $request->boolean('marketing_consent')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'primary_phone' => ['required', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail(__('Phone must be a Bangladesh mobile number like 01XXXXXXXXX.'))],
            'extra_phones' => ['array', 'max:5'],
            'extra_phones.*' => ['nullable', fn ($a, $v, $fail) => $v === null || $v === '' || Phone::isValid($v) ?: $fail(__('Phone must be a Bangladesh mobile number like 01XXXXXXXXX.'))],
            'whatsapp_number' => ['nullable', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail(__('Phone must be a Bangladesh mobile number like 01XXXXXXXXX.'))],
            'messenger_psid' => ['nullable', 'string', 'max:64'],
            'risk_level' => ['required', Rule::in(['normal', 'watch', 'blocked'])],
            'blocked_reason' => ['nullable', 'required_if:risk_level,blocked', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'marketing_consent' => ['boolean'],
            'addresses' => ['array', 'max:10'],
            'addresses.*.id' => ['nullable', 'integer'],
            'addresses.*.address_line' => ['nullable', 'string', 'max:500'],
            'addresses.*.district' => ['nullable', 'string', 'max:60'],
            'addresses.*.thana' => ['nullable', 'string', 'max:80'],
            'addresses.*.zone_id' => ['nullable', Rule::exists('delivery_zones', 'id')],
            'addresses.*.is_default' => ['nullable'],
        ]);
    }

    private function formData(Customer $customer): array
    {
        return [
            'customer' => $customer,
            'zones' => DeliveryZone::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all(),
            'districts' => config('bd.districts'),
        ];
    }
}
