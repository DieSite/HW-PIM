<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Excluding or re-including competitor prices from Tools → Concurrentieprijzen:
 * either the checked prices (action + ids) or the one price of a row button
 * (row_action = "exclude:<id>" or "include:<id>").
 */
class CompetitorPricesBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return bouncer()->hasPermission('catalog.products.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'row_action' => ['nullable', 'string', 'regex:/^(exclude|include):\d+$/'],
            'action'     => ['required_without:row_action', 'nullable', Rule::in(['exclude', 'include'])],
            'ids'        => ['required_without:row_action', 'array'],
            'ids.*'      => ['integer'],
            'reason'     => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required_without' => 'Selecteer eerst een of meer prijzen.',
            'reason.max'           => 'Houd de reden korter dan 500 tekens.',
        ];
    }

    /**
     * @return array{action: string, ids: list<int>}
     */
    public function selection(): array
    {
        $row = $this->validated('row_action');

        if ($row !== null) {
            [$action, $id] = explode(':', $row);

            return ['action' => $action, 'ids' => [(int) $id]];
        }

        return [
            'action' => (string) $this->validated('action'),
            'ids'    => array_values(array_unique(array_map('intval', (array) $this->validated('ids')))),
        ];
    }
}
