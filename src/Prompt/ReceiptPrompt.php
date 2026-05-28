<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Prompt;

use InvalidArgumentException;

class ReceiptPrompt
{
    /**
     * Canonical receipt fields supported by this package.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'merchant',
        'total_amount',
        'currency',
        'date',
        'vat_amount',
        'mcc',
        'vats',
        'line_items',
        'confidence',
        'tip',
        'purchase_country',
        'purchase_city',
    ];

    /**
     * Build the main extraction prompt.
     *
     * @param array<int, string>|array<string, mixed> $enabledFields
     */
    public function build(array $enabledFields, string $inputType): string
    {
        $inputType = $this->normalizeInputType($inputType);
        $fields = $this->normalizeFields($enabledFields);
        $schema = $this->expectedJson($fields, $inputType);

        $inputInstruction = $inputType === 'images'
            ? 'Analyze all provided images together as one receipt. Preserve image order and merge the content into one combined receipt analysis.'
            : 'Analyze the provided PDF as one receipt. Use all pages that belong to the receipt and preserve reading order.';

        return implode("\n", [
            'You are a receipt extraction engine.',
            $inputInstruction,
            '',
            'Return JSON only. Do not wrap the JSON in markdown. Do not add commentary, explanations, code fences, or prose outside the JSON object.',
            'Return only valid JSON matching the exact schema below. No wrapper object, no extra fields, no aliases, no markdown, no comments, and no explanation.',
            'Use the exact top-level keys shown in the expected JSON shape. Omit every top-level field that is not shown.',
            'Use null for unknown scalar values. Use [] for unknown arrays.',
            'Do not invent data. If a value is unclear, return null.',
            'The mcc field is a best-effort AI estimate only. Receipts generally do not contain MCC, so infer it from the merchant and receipt context.',
            'The tip field must be a numeric tip or gratuity amount when visible on the receipt; otherwise return null.',
            'The purchase_country field must be the purchase country when inferable from receipt text, merchant address, currency, or visible location; otherwise return null.',
            'The purchase_city field must be the purchase city when visible or clearly inferable from receipt text or address; otherwise return null.',
            'VAT breakdown must always be vats: array<object>. Never return vats as a string.',
            'line_items must always be an array of objects with description, quantity, unit_price, and amount.',
            'vats must always be an array of objects with rate, amount, amount_inc_vat, and amount_ex_vat.',
            'Use numeric values without currency symbols. Normalize decimal separators to dot. Use ISO dates where possible.',
            '',
            'Expected JSON shape:',
            $this->encodeJson($schema),
        ]);
    }

    /**
     * Build a repair prompt for invalid JSON responses.
     *
     * @param array<int, string>|array<string, mixed> $enabledFields
     */
    public function repair(array $enabledFields, string $inputType, string $rawText): string
    {
        $inputType = $this->normalizeInputType($inputType);
        $fields = $this->normalizeFields($enabledFields);
        $schema = $this->expectedJson($fields, $inputType);

        return implode("\n", [
            'The previous model response was not valid JSON or did not match the required receipt schema.',
            'Repair it into valid JSON only. Do not add markdown, commentary, code fences, or any text outside the JSON object.',
            'Keep the same extracted facts where possible, but use null for unknown or unrecoverable values.',
            '',
            'Expected JSON shape:',
            $this->encodeJson($schema),
            '',
            'Previous response to repair:',
            $rawText,
        ]);
    }

    /**
     * Alias for callers that prefer an explicit extraction method name.
     *
     * @param array<int, string>|array<string, mixed> $enabledFields
     */
    public function extraction(array $enabledFields, string $inputType): string
    {
        return $this->build($enabledFields, $inputType);
    }

    /**
     * Return an example JSON object containing only enabled fields.
     *
     * @param array<int, string>|array<string, mixed> $enabledFields
     * @return array<string, mixed>
     */
    public function expectedJson(array $enabledFields, string $inputType = 'images'): array
    {
        $this->normalizeInputType($inputType);
        $fields = $this->normalizeFields($enabledFields);

        $json = [];

        foreach ($fields as $field) {
            $json[$field] = match ($field) {
                'merchant' => null,
                'total_amount' => null,
                'currency' => null,
                'date' => null,
                'vat_amount' => null,
                'mcc' => null,
                'vats' => [[
                    'rate' => null,
                    'amount' => null,
                    'amount_inc_vat' => null,
                    'amount_ex_vat' => null,
                ]],
                'line_items' => [[
                    'description' => null,
                    'quantity' => null,
                    'unit_price' => null,
                    'amount' => null,
                ]],
                'confidence' => null,
                'tip' => null,
                'purchase_country' => null,
                'purchase_city' => null,
                default => null,
            };
        }

        return $json;
    }

    /**
     * Build a strict JSON schema for providers that support schema-based output.
     *
     * @param array<int, string>|array<string, mixed> $enabledFields
     * @return array<string, mixed>
     */
    public function jsonSchema(array $enabledFields, string $inputType = 'images'): array
    {
        $this->normalizeInputType($inputType);
        $fields = $this->normalizeFields($enabledFields);

        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = match ($field) {
                'merchant' => $this->nullableStringSchema(),
                'total_amount' => $this->nullableNumberSchema(),
                'currency' => $this->nullableStringSchema(),
                'date' => $this->nullableStringSchema(),
                'vat_amount' => $this->nullableNumberSchema(),
                'mcc' => $this->nullableStringSchema(),
                'vats' => [
                    'type' => 'array',
                    'items' => $this->objectSchema([
                        'rate' => $this->nullableNumberSchema(),
                        'amount' => $this->nullableNumberSchema(),
                        'amount_inc_vat' => $this->nullableNumberSchema(),
                        'amount_ex_vat' => $this->nullableNumberSchema(),
                    ]),
                ],
                'line_items' => [
                    'type' => 'array',
                    'items' => $this->objectSchema([
                        'description' => $this->nullableStringSchema(),
                        'quantity' => $this->nullableNumberSchema(),
                        'unit_price' => $this->nullableNumberSchema(),
                        'amount' => $this->nullableNumberSchema(),
                    ]),
                ],
                'confidence' => $this->nullableNumberSchema(),
                'tip' => $this->nullableNumberSchema(),
                'purchase_country' => $this->nullableStringSchema(),
                'purchase_city' => $this->nullableStringSchema(),
                default => $this->nullableStringSchema(),
            };
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return self::FIELDS;
    }

    private function normalizeInputType(string $inputType): string
    {
        $inputType = strtolower(trim($inputType));

        if (! in_array($inputType, ['images', 'pdf'], true)) {
            throw new InvalidArgumentException('Receipt prompt input type must be either images or pdf.');
        }

        return $inputType;
    }

    /**
     * @param array<int, string>|array<string, mixed> $enabledFields
     * @return list<string>
     */
    private function normalizeFields(array $enabledFields): array
    {
        if ($enabledFields === []) {
            return self::FIELDS;
        }

        $fields = [];

        foreach ($enabledFields as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                if (! $value) {
                    continue;
                }
                $field = $key;
            } elseif (is_string($key) && is_scalar($value)) {
                if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $field = $key;
            } elseif (is_int($key) && is_string($value)) {
                $field = $value;
            } else {
                continue;
            }

            $field = strtolower(trim($field));

            if (in_array($field, self::FIELDS, true) && ! in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        if ($fields === []) {
            return self::FIELDS;
        }

        return array_values(array_filter(self::FIELDS, static fn (string $field): bool => in_array($field, $fields, true)));
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function objectSchema(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableStringSchema(): array
    {
        return ['type' => ['string', 'null']];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableNumberSchema(): array
    {
        return ['type' => ['number', 'null']];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new InvalidArgumentException('Unable to encode receipt prompt JSON shape.');
        }

        return $json;
    }
}
