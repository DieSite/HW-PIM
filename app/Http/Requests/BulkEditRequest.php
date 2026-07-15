<?php

namespace App\Http\Requests;

use App\Services\BulkEditService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkEditRequest extends FormRequest
{
    public function __construct(private BulkEditService $bulkEditService)
    {
        parent::__construct();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $codes = $this->bulkEditService->editableAttributeCodes();

        return [
            'target'              => ['required', 'string', Rule::in($codes)],
            'type'                => ['required', 'string', Rule::in(['replace', 'set', 'clear'])],
            'find'                => ['nullable', 'string', 'required_if:type,replace'],
            'replace'             => ['nullable', 'string'],
            'value'               => ['nullable', 'string', 'required_if:type,set'],
            'sync_woo'            => ['nullable', 'boolean'],

            'brand'               => ['nullable', 'string'],
            'sku_prefix'          => ['nullable', 'string', 'max:50'],
            'scope'               => ['nullable', Rule::in(['all', 'parents', 'variants'])],
            'condition_attribute' => ['nullable', 'string', Rule::in($codes)],
            'condition_operator'  => ['nullable', Rule::in(['contains', 'equals', 'empty'])],
            'condition_value'     => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'find.required_if'  => 'Vul een zoektekst in voor "Zoeken & vervangen".',
            'value.required_if' => 'Vul een waarde in voor "Waarde instellen".',
        ];
    }

    /**
     * @return array{brand?:string, sku_prefix?:string, scope?:string, condition_attribute?:string, condition_operator?:string, condition_value?:string}
     */
    public function filters(): array
    {
        return array_filter([
            'brand'               => $this->input('brand'),
            'sku_prefix'          => $this->input('sku_prefix'),
            'scope'               => $this->input('scope'),
            'condition_attribute' => $this->input('condition_attribute'),
            'condition_operator'  => $this->input('condition_operator'),
            'condition_value'     => $this->input('condition_value'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array{target:string, type:string, find?:string, replace?:string, value?:string}
     */
    public function operation(): array
    {
        return array_filter([
            'target'  => $this->input('target'),
            'type'    => $this->input('type'),
            'find'    => $this->input('find'),
            'replace' => $this->input('replace'),
            'value'   => $this->input('value'),
        ], fn ($value) => $value !== null);
    }
}
