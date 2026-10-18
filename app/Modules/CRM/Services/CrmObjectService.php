<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmObjectType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates + normalises custom-object record data against its type's field
 * schema (spec section 11). Only declared fields are kept, required fields are
 * enforced, and values are coerced to their declared type.
 */
class CrmObjectService
{
    /**
     * @param  array  $input  raw {field_name: value}
     * @return array  cleaned data limited to declared fields
     *
     * @throws ValidationException
     */
    public function validateData(CrmObjectType $type, array $input): array
    {
        $rules     = [];
        $cleanKeys = [];

        foreach ($type->fields ?? [] as $field) {
            $name = $field['name'] ?? null;
            if (! $name) continue;
            $cleanKeys[] = $name;

            $rule = [];
            $rule[] = ($field['required'] ?? false) ? 'required' : 'nullable';

            switch ($field['type'] ?? 'text') {
                case 'number':      $rule[] = 'numeric'; break;
                case 'date':        $rule[] = 'date'; break;
                case 'boolean':     $rule[] = 'boolean'; break;
                case 'select':      if (! empty($field['options'])) $rule[] = 'in:' . implode(',', $field['options']); break;
                case 'multiselect': $rule[] = 'array'; break;
                default:            $rule[] = 'string';
            }

            $rules["data.{$name}"] = implode('|', $rule);
        }

        $payload = ['data' => array_intersect_key($input, array_flip($cleanKeys))];

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->coerce($type, $payload['data']);
    }

    /**
     * Best display label for a record (the configured record_label field, or
     * the first text field, or the record id).
     */
    public function recordLabel(CrmObjectType $type, array $data, int $id): string
    {
        $key = $type->record_label;
        if ($key && ! empty($data[$key])) {
            return (string) $data[$key];
        }
        foreach ($type->fields ?? [] as $field) {
            if (($field['type'] ?? '') === 'text' && ! empty($data[$field['name']])) {
                return (string) $data[$field['name']];
            }
        }
        return '#' . $id;
    }

    private function coerce(CrmObjectType $type, array $data): array
    {
        $byName = collect($type->fields ?? [])->keyBy('name');

        foreach ($data as $name => $value) {
            $type_ = $byName[$name]['type'] ?? 'text';
            $data[$name] = match ($type_) {
                'number'  => $value === null || $value === '' ? null : $value + 0,
                'boolean' => (bool) $value,
                default   => $value,
            };
        }

        return $data;
    }
}
