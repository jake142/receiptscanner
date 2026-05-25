<?php

declare(strict_types=1);

namespace Jake142\ReceiptScanner;

class ReceiptScannerManager
{
    public function __construct(
        private readonly ReceiptScannerService $service,
    ) {
    }

    /**
     * Scan one receipt represented by one or more image files.
     *
     * @param array<int, mixed> $images
     * @return array<string, mixed>
     */
    public function scanImages(array $images): array
    {
        return $this->service->scanImages($images);
    }

    /**
     * Scan one receipt represented by exactly one PDF file.
     *
     * @param mixed $pdf
     * @return array<string, mixed>
     */
    public function scanPdf(mixed $pdf): array
    {
        return $this->service->scanPdf($pdf);
    }
}
