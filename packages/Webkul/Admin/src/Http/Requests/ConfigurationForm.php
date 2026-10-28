<?php

namespace Webkul\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigurationForm extends FormRequest
{
    /**
     * Determine if the Configuration is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return collect(request()->input('keys', []))->mapWithKeys(function ($item) {
            $data = json_decode($item, true);

            return collect($data['fields'])->mapWithKeys(function ($field) use ($data) {
                $key = $data['key'].'.'.$field['name'];

                // Check delete key exist in the request
                if (! $this->has($key.'.delete')) {
                    $validation = isset($field['validation']) && $field['validation'] ? $field['validation'] : 'nullable';

                    if (in_array($field['type'] ?? null, ['image', 'file'])) {
                        $validation .= '|file';
                    }

                    return [$key => $validation];
                }

                return [];
            })->toArray();
        })->toArray();
    }

    /**
     * An upload PHP refused (most often: larger than upload_max_filesize)
     * arrives without a stored file. Without the file rule above that passed
     * validation and the save reported success while nothing was stored.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return collect(request()->input('keys', []))->mapWithKeys(function ($item) {
            $data = json_decode($item, true);

            return collect($data['fields'])
                ->filter(fn (array $field): bool => in_array($field['type'] ?? null, ['image', 'file']))
                ->mapWithKeys(fn (array $field): array => [
                    $data['key'].'.'.$field['name'].'.uploaded' => sprintf(
                        '"%s" kon niet worden geüpload. Het bestand mag maximaal %s groot zijn.',
                        trans($field['title']),
                        ini_get('upload_max_filesize')
                    ),
                ])
                ->toArray();
        })->toArray();
    }
}
