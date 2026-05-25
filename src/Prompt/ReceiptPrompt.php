<?php

namespace Jake142\ReceiptScanner\Prompt;

use InvalidArgumentException;

class ReceiptPrompt
{
    /**
     * @var list<string>
     */
    private const CONFIGURABLE_SECTIONS = [
        'merchant',
        'receipt',
        'totals',
        'vat_breakdown',
        'line_items',
        'mcc',
        'confidence',
        'warnings',
        'metadata',
    ];

    /**
     * Build the primary receipt extraction prompt for the configured schema sections.
     *
     * @param array<int, string>|array<string, mixed> $enabledSections
     */
    public function build(array $enabledSections, string $inputType): string
    {
        $inputType = $this->normalizeInputType($inputType);
        $sections = $this->normalizeSections($enabledSections);
        $shape = $this->expectedJson($sections, $inputType);

        $inputInstruction = $inputType === 'images'
            ? 'Analyze all provided images together as one long receipt. Preserve the provided image order and read the receipt from top to bottom across all images.'
            : 'Analyze the provided PDF as one receipt. Use all pages that belong to the receipt and preserve top-to-bottom reading order.';

        return implode("\n", [
            'You are a receipt extraction engine. Extract structured receipt data from the provided file content.',
            $inputInstruction,
            '',
            'Return JSON only. Do not wrap the JSON in markdown. Do not add commentary, explanations, code fences, or prose outside the JSON object.',
            'Use the exact top-level keys shown in the expected JSON shape. Omit every top-level section that is not shown.',
            'Use null for unknown or unreadable scalar values. Do not use empty strings for unknown values.',
            'Use numbers for monetary, quantity, rate, and confidence values. Do not return localized currency strings such as "1 234,50 kr".',
            'Be conservative: do not invent exact merchant details, dates, totals, VAT rates, or line items. If a value is unclear, use null and add a warning when the warnings section is enabled.',
            'If line items span multiple images or pages, merge them in receipt order and avoid duplicates.',
            'If totals and line items conflict, preserve the printed totals and add a warning when the warnings section is enabled.',
            'The mcc section, when enabled, is a best-effort AI estimate only. Receipts generally do not contain MCC. Estimate MCC from merchant name, business type, line item categories, and receipt context; include confidence and a short reason.',
            'The metadata section, when enabled, must describe this extraction call, including provider, model, input_type, and image_count when known. If provider or model is not visible to you, use null or the value supplied by the caller context.',
            '',
            'Expected JSON shape:',
            $this->encodeJson($shape),
        ]);
    }

    /**
     * Build a lightweight JSON repair prompt for one retry after invalid JSON.
     *
     * @param array<int, string>|array<string, mixed> $enabledSections
     */
    public function repair(array $enabledSections, string $inputType, string $rawText): string
    {
        $inputType = $this->normalizeInputType($inputType);
        $sections = $this->normalizeSections($enabledSections);
        $shape = $this->expectedJson($sections, $inputType);

        return implode("\n", [
            'The previous model response was not valid JSON or did not match the required receipt schema.',
            'Repair it into valid JSON only. Do not add markdown, commentary, code fences, or any text outside the JSON object.',
            'Keep the same extracted facts where possible, but use null for unknown or unrecoverable values.',
            'Use exactly the top-level keys shown in the expected JSON shape and omit every top-level section that is not shown.',
            '',
            'Expected JSON shape:',
            $this->encodeJson($shape),
            '',
            'Previous response to repair:',
            $rawText,
        ]);
    }

    /**
     * Alias for callers that prefer an explicit extraction method name.
     *
     * @param array<int, string>|array<string, mixed> $enabledSections
     */
    public function extraction(array $enabledSections, string $inputType): string
    {
        return $this->build($enabledSections, $inputType);
    }

    /**
     * Return an example JSON object containing only enabled sections.
     *
     * @param array<int, string>|array<string, mixed> $enabledSections
     * @return array<string, mixed>
     */
    public function expectedJson(array $enabledSections, string $inputType = 'images'): array
    {
        $inputType = $this->normalizeInputType($inputType);
        $sections = $this->normalizeSections($enabledSections);

        $json = [
            'schema_version' => '1.0',
        ];

        foreach ($sections as $section) {
            $json[$section] = match ($section) {
                'merchant' => [
                    'name' => 'string|null',
                    'address' => 'string|null',
                    'organization_number' => 'string|null',
                    'vat_number' => 'string|null',
                ],
                'receipt' => [
                    'date' => 'YYYY-MM-DD|null',
                    'time' => 'HH:MM:SS|null',
                    'receipt_number' => 'string|null',
                    'payment_method' => 'string|null',
                    'country' => 'ISO-3166-1 alpha-2|null',
                    'language' => 'ISO-639-1|null',
                    'currency' => 'ISO-4217|null',
                ],
                'totals' => [
                    'subtotal' => 'number|null',
                    'discount' => 'number|null',
                    'rounding' => 'number|null',
                    'tip' => 'number|null',
                    'vat_total' => 'number|null',
                    'total' => 'number|null',
                ],
                'vat_breakdown' => [[
                    'rate' => 'number|null',
                    'net' => 'number|null',
                    'vat' => 'number|null',
                    'gross' => 'number|null',
                ]],
                'line_items' => [[
                    'description' => 'string|null',
                    'quantity' => 'number|null',
                    'unit_price' => 'number|null',
                    'total' => 'number|null',
                    'vat_rate' => 'number|null',
                    'sku' => 'string|null',
                    'category' => 'string|null',
                ]],
                'mcc' => [
                    'code' => 'string|null',
                    'confidence' => 'number',
                    'reason' => 'string|null',
                ],
                'confidence' => [
                    'overall' => 'number',
                    'date' => 'number|null',
                    'merchant' => 'number|null',
                    'total' => 'number|null',
                    'line_items' => 'number|null',
                    'vat' => 'number|null',
                ],
                'warnings' => ['string'],
                'metadata' => [
                    'provider' => 'string|null',
                    'model' => 'string|null',
                    'input_type' => $inputType,
                    'image_count' => $inputType === 'images' ? 'number|null' : null,
                ],
            };
        }

        return $json;
    }

    /**
     * Build a strict JSON schema suitable for providers that support JSON schema response formats.
     *
     * @param array<int, string>|array<string, mixed> $enabledSections
     * @return array<string, mixed>
     */
    public function jsonSchema(array $enabledSections, string $inputType = 'images'): array
    {
        $this->normalizeInputType($inputType);
        $sections = $this->normalizeSections($enabledSections);

        $properties = [
            'schema_version' => [
                'type' => 'string',
                'const' => '1.0',
            ],
        ];

        foreach ($sections as $section) {
            $properties[$section] = match ($section) {
                'merchant' => $this->objectSchema([
                    'name' => $this->nullableString(),
                    'address' => $this->nullableString(),
                    'organization_number' => $this->nullableString(),
                    'vat_number' => $this->nullableString(),
                ]),
                'receipt' => $this->objectSchema([
                    'date' => $this->nullableString(),
                    'time' => $this->nullableString(),
                    'receipt_number' => $this->nullableString(),
                    'payment_method' => $this->nullableString(),
                    'country' => $this->nullableString(),
                    'language' => $this->nullableString(),
                    'currency' => $this->nullableString(),
                ]),
                'totals' => $this->objectSchema([
                    'subtotal' => $this->nullableNumber(),
                    'discount' => $this->nullableNumber(),
                    'rounding' => $this->nullableNumber(),
                    'tip' => $this->nullableNumber(),
                    'vat_total' => $this->nullableNumber(),
                    'total' => $this->nullableNumber(),
                ]),
                'vat_breakdown' => [
                    'type' => 'array',
                    'items' => $this->objectSchema([
                        'rate' => $this->nullableNumber(),
                        'net' => $this->nullableNumber(),
                        'vat' => $this->nullableNumber(),
                        'gross' => $this->nullableNumber(),
                    ]),
                ],
                'line_items' => [
                    'type' => 'array',
                    'items' => $this->objectSchema([
                        'description' => $this->nullableString(),
                        'quantity' => $this->nullableNumber(),
                        'unit_price' => $this->nullableNumber(),
                        'total' => $this->nullableNumber(),
                        'vat_rate' => $this->nullableNumber(),
                        'sku' => $this->nullableString(),
                        'category' => $this->nullableString(),
                    ]),
                ],
                'mcc' => $this->objectSchema([
                    'code' => $this->nullableString(),
                    'confidence' => ['type' => 'number'],
                    'reason' => $this->nullableString(),
                ]),
                'confidence' => $this->objectSchema([
                    'overall' => ['type' => 'number'],
                    'date' => $this->nullableNumber(),
                    'merchant' => $this->nullableNumber(),
                    'total' => $this->nullableNumber(),
                    'line_items' => $this->nullableNumber(),
                    'vat' => $this->nullableNumber(),
                ]),
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'metadata' => $this->objectSchema([
                    'provider' => $this->nullableString(),
                    'model' => $this->nullableString(),
                    'input_type' => ['type' => 'string', 'enum' => ['images', 'pdf']],
                    'image_count' => $this->nullableNumber(),
                ]),
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
     * Return the recognized configurable receipt sections in their canonical order.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return self::CONFIGURABLE_SECTIONS;
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
     * @param array<int, string>|array<string, mixed> $enabledSections
     * @return list<string>
     */
    private function normalizeSections(array $enabledSections): array
    {
        if ($enabledSections === []) {
            return [];
        }

        $sections = [];

        foreach ($enabledSections as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                if (! $value) {
                    continue;
                }

                $section = $key;
            } elseif (is_string($key) && is_scalar($value)) {
                if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $section = $key;
            } elseif (is_string($value)) {
                $section = $value;
            } else {
                continue;
            }

            $section = strtolower(trim($section));

            if ($section === 'schema_version') {
                continue;
            }

            if (in_array($section, self::CONFIGURABLE_SECTIONS, true) && ! in_array($section, $sections, true)) {
                $sections[] = $section;
            }
        }

        return array_values(array_filter(
            self::CONFIGURABLE_SECTIONS,
            static fn (string $section): bool => in_array($section, $sections, true),
        ));
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
    private function nullableString(): array
    {
        return ['type' => ['string', 'null']];
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableNumber(): array
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
