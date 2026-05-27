<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner\Providers\Concerns;

use stdClass;

trait ExtractsOpenAiResponsesText
{
    /**
     * Extract generated text from an OpenAI/Azure OpenAI Responses API body.
     *
     * The Responses API may expose a convenience top-level `output_text` field,
     * but Azure OpenAI responses do not always include it. When the aggregate
     * field is absent or empty, generated text is read from the documented
     * nested `output[*].content[*]` blocks in response order.
     */
    protected function extractOpenAiResponsesText(array|stdClass $body): ?string
    {
        $outputText = $this->openAiResponsesStringValue($this->openAiResponsesValue($body, 'output_text'));

        if ($outputText !== null && trim($outputText) !== '') {
            return $outputText;
        }

        $textParts = [];
        $output = $this->openAiResponsesValue($body, 'output');

        foreach ($this->openAiResponsesIterable($output) as $outputItem) {
            $this->collectOpenAiResponsesOutputItemText($outputItem, $textParts);
        }

        if ($textParts === []) {
            return null;
        }

        return implode("\n", $textParts);
    }

    /**
     * @param array<int, string> $textParts
     */
    private function collectOpenAiResponsesOutputItemText(mixed $outputItem, array &$textParts): void
    {
        if (! is_array($outputItem) && ! $outputItem instanceof stdClass) {
            return;
        }

        $content = $this->openAiResponsesValue($outputItem, 'content');

        if ($content === null) {
            $this->collectOpenAiResponsesContentBlockText($outputItem, $textParts);

            return;
        }

        foreach ($this->openAiResponsesIterable($content) as $contentBlock) {
            $this->collectOpenAiResponsesContentBlockText($contentBlock, $textParts);
        }
    }

    /**
     * @param array<int, string> $textParts
     */
    private function collectOpenAiResponsesContentBlockText(mixed $contentBlock, array &$textParts): void
    {
        if (! is_array($contentBlock) && ! $contentBlock instanceof stdClass) {
            $text = $this->openAiResponsesStringValue($contentBlock);

            if ($text !== null && trim($text) !== '') {
                $textParts[] = $text;
            }

            return;
        }

        $type = $this->openAiResponsesStringValue($this->openAiResponsesValue($contentBlock, 'type'));

        if ($type !== null && ! in_array($type, ['output_text', 'text'], true)) {
            return;
        }

        $text = $this->openAiResponsesStringValue($this->openAiResponsesValue($contentBlock, 'text'));

        if ($text === null) {
            $text = $this->openAiResponsesStringValue($this->openAiResponsesValue($contentBlock, 'output_text'));
        }

        if ($text !== null && trim($text) !== '') {
            $textParts[] = $text;
        }
    }

    /**
     * @return iterable<int|string, mixed>
     */
    private function openAiResponsesIterable(mixed $value): iterable
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        return [];
    }

    private function openAiResponsesValue(array|stdClass $source, string $key): mixed
    {
        if (is_array($source)) {
            return array_key_exists($key, $source) ? $source[$key] : null;
        }

        return property_exists($source, $key) ? $source->{$key} : null;
    }

    private function openAiResponsesStringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value) || $value instanceof stdClass) {
            $nestedValue = $this->openAiResponsesValue($value, 'value');

            if ($nestedValue !== null) {
                return $this->openAiResponsesStringValue($nestedValue);
            }
        }

        return null;
    }
}
