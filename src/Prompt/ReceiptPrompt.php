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
        'receipt',
        'totals',
        'vats',
        'line_items',
        'payment',
        'confidence',
        'provider',
        'model',
        'raw',
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
            'Use the exact top-level keys shown in the expected JSON shape. Omit every top-level field that is not shown.',
            'Use null for unknown scalar values. Use [] for unknown arrays.',
            'Do not invent data. If a value is unclear, return null.',
            'The mcc field is a best-effort AI estimate only. Receipts generally do not contain MCC, so infer it from the merchant and receipt context.',
            'VAT breakdown must always be vats: array<object>. Never return vats as a string.',
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
                'merchant' => [
                    'name' => null,
                    'organization_number' => null,
                    'address' => null,
                ],
                'receipt' => [
                    'receipt_number' => null,
                    'purchase_date' => null,
                    'purchase_time' => null,
                    'currency' => null,
                    'mcc' => null,
                ],
                'totals' => [
                    'amount_excluding_vat' => null,
                    'vat_amount' => null,
                    'amount_including_vat' => null,
                    'rounding' => null,
                ],
                'vats' => [[
                    'vat_rate' => null,
                    'amount_excluding_vat' => null,
                    'vat_amount' => null,
                    'amount_including_vat' => null,
                ]],
                'line_items' => [[
                    'description' => null,
                    'quantity' => null,
                    'unit_price' => null,
                    'amount_including_vat' => null,
                    'vat_rate' => null,
                    'category' => null,
                ]],
                'payment' => [
                    'method' => null,
                    'card_last4' => null,
                ],
                'confidence' => null,
                'provider' => null,
                'model' => null,
                'raw' => null,
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
                'merchant' => $this->objectSchema([
                    'name' => $this->nullableStringSchema(),
                    'organization_number' => $this->nullableStringSchema(),
                    'address' => $this->nullableStringSchema(),
                ]),
                'receipt' => $this->objectSchema([
                    'receipt_number' => $this->nullableStringSchema(),
                    'purchase_date' => $this->nullableStringSchema(),
                    'purchase_time' => $this->nullableStringSchema(),
                    'currency' => $this->nullableStringSchema(),
                    'mcc' => $this->nullableStringSchema(),
                ]),
                'totals' => $this->objectSchema([
                    'amount_excluding_vat' => $this->nullableNumberSchema(),
                    'vat_amount' => $this->nullableNumberSchema(),
                    'amount_including_vat' => $this->nullableNumberSchema(),
                    'rounding' => $this->nullableNumberSchema(),
                ]),
                'vats' => [
                    'type' => 'array',
                    'items' => $this->objectSchema([
                        'vat_rate' => $this->nullableNumberSchema(),
                        'amount_excluding_vat' => $this->nullableNumberSchema(),
                        'vat_amount' => $this->nullableNumberSchema(),
                        'amount_including_vat' => $this->nullableNumberSchema(),
                    ]),
                ],
                'line_items' => [
                    'type' => 'array',
                    'items' => $this->objectSchema([
                        'description' => $this->nullableStringSchema(),
                        'quantity' => $this->nullableNumberSchema(),
                        'unit_price' => $this->nullableNumberSchema(),
                        'amount_including_vat' => $this->nullableNumberSchema(),
                        'vat_rate' => $this->nullableNumberSchema(),
                        'category' => $this->nullableStringSchema(),
                    ]),
                ],
                'payment' => $this->objectSchema([
                    'method' => $this->nullableStringSchema(),
                    'card_last4' => $this->nullableStringSchema(),
                ]),
                'confidence' => $this->nullableNumberSchema(),
                'provider' => $this->nullableStringSchema(),
                'model' => $this->nullableStringSchema(),
                'raw' => ['type' => ['null']],
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
        $hasExplicitBooleanMap = false;

        foreach ($enabledFields as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                $hasExplicitBooleanMap = true;
                if (! $value) {
                    continue;
                }

                $field = $key;
            } elseif (is_string($key) && is_scalar($value)) {
                $hasExplicitBooleanMap = true;
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
