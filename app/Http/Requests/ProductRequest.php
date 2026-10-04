<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $variantIds = $product ? $product->variants()->pluck('id')->all() : [];

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'status' => ['required', Rule::in(['active', 'draft', 'archived'])],
            'base_unit' => ['required', Rule::in(['pcs', 'g'])],
            'short_description' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:100000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'focus_keyword' => ['nullable', 'string', 'max:150'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'tags' => ['nullable', 'string', 'max:500'],

            'variants' => ['required', 'array', 'min:1', 'max:50'],
            'variants.*.id' => ['nullable', 'integer', Rule::in($variantIds)],
            'variants.*.name' => ['required', 'string', 'max:150'],
            'variants.*.sku' => ['required', 'string', 'max:60', 'distinct'],
            'variants.*.barcode' => ['nullable', 'string', 'max:60', 'distinct'],
            'variants.*.unit' => ['required', Rule::in(['pcs', 'g', 'box'])],
            'variants.*.pack_qty' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'variants.*.weight_g' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'variants.*.is_active' => ['boolean'],
            'variants.*.prices' => ['array'],
            'variants.*.prices.*.regular' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'variants.*.prices.*.sale' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'variants.0.prices.online.regular' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return ['variants.0.prices.online.regular.required' => __('The online regular price is required.')];
    }

    /** SKU and barcode must be unique across all products (not only in this form). */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ($this->input('variants', []) as $i => $row) {
                foreach (['sku', 'barcode'] as $field) {
                    $value = trim((string) ($row[$field] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $taken = \Illuminate\Support\Facades\DB::table('product_variants')->where($field, $value)
                        ->when($row['id'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->exists();
                    if ($taken) {
                        $validator->errors()->add("variants.{$i}.{$field}", __('This :field is already used by another product.', ['field' => strtoupper($field)]));
                    }
                }
                $prices = $row['prices'] ?? [];
                foreach ($prices as $list => $p) {
                    if (($p['sale'] ?? '') !== '' && ($p['regular'] ?? '') !== '' && (float) $p['sale'] > (float) $p['regular']) {
                        $validator->errors()->add("variants.{$i}.prices.{$list}.sale", __('Sale price cannot be above the regular price.'));
                    }
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        // A role that hides cost price may not change it either.
        $canSeeCost = (bool) $this->user()?->canSeeField('cost_price');

        $variants = collect($this->input('variants', []))->map(function ($v) use ($canSeeCost) {
            $v = [...$v, 'is_active' => filter_var($v['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN), 'sku' => trim((string) ($v['sku'] ?? ''))];
            if (! $canSeeCost) {
                unset($v['cost_price']);
            }

            return $v;
        })->values()->all();

        $this->merge(['variants' => $variants, 'name' => trim((string) $this->input('name'))]);
    }
}
