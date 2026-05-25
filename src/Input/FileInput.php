<?php

namespace Jake142\ReceiptScanner\Input;

use InvalidArgumentException;
use SplFileInfo;

class FileInput
{
    /** @var list<string> */
    public const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public const PDF_MIME_TYPE = 'application/pdf';

    /** @var list<string> */
    public const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ];

    public readonly string $source;
    public readonly string $filename;
    public readonly string $mime;
    public readonly string $bytes;
    public readonly int $size;
    public readonly string $base64;
    public readonly string $data_uri;

    public function __construct(
        string $source,
        string $filename,
        string $mime,
        string $bytes,
        ?int $size = null,
        ?string $base64 = null,
        ?string $dataUri = null,
    ) {
        $mime = self::normalizeMime($mime);
        $size ??= strlen($bytes);
        $base64 ??= base64_encode($bytes);
        $dataUri ??= 'data:' . $mime . ';base64,' . $base64;

        $this->source = $source;
        $this->filename = self::sanitizeFilename($filename, $mime);
        $this->mime = $mime;
        $this->bytes = $bytes;
        $this->size = $size;
        $this->base64 = $base64;
        $this->data_uri = $dataUri;
    }

    /**
     * Normalize a local path, SplFileInfo, UploadedFile-like object, or data URI into a safe provider payload.
     *
     * @param mixed $input
     * @param list<string> $allowedMimes
     */
    public static function from(mixed $input, array $allowedMimes = [], int|float|null $maxFileSizeMb = null): self
    {
        $allowedMimes = self::normalizeAllowedMimes($allowedMimes);

        if (is_string($input)) {
            if (self::looksLikeDataUri($input)) {
                return self::fromDataUri($input, $allowedMimes, $maxFileSizeMb);
            }

            return self::fromPath($input, $allowedMimes, $maxFileSizeMb, 'path');
        }

        if ($input instanceof SplFileInfo) {
            return self::fromPath($input->getPathname(), $allowedMimes, $maxFileSizeMb, 'spl_file_info', $input->getFilename(), $input);
        }

        if (is_object($input) && self::isUploadedFileLike($input)) {
            if (method_exists($input, 'isValid') && $input->isValid() === false) {
                throw new InvalidArgumentException('The uploaded receipt file is not valid.');
            }

            $path = self::pathFromObject($input);
            $filename = method_exists($input, 'getClientOriginalName') ? (string) $input->getClientOriginalName() : null;

            return self::fromPath($path, $allowedMimes, $maxFileSizeMb, 'uploaded_file', $filename, $input);
        }

        throw new InvalidArgumentException('Receipt input must be a local file path, SplFileInfo, uploaded file, or base64 data URI string.');
    }

    public static function image(mixed $input, int|float|null $maxFileSizeMb = null): self
    {
        return self::from($input, self::IMAGE_MIME_TYPES, $maxFileSizeMb);
    }

    public static function pdf(mixed $input, int|float|null $maxFileSizeMb = null): self
    {
        return self::from($input, [self::PDF_MIME_TYPE], $maxFileSizeMb);
    }

    public function isImage(): bool
    {
        return in_array($this->mime, self::IMAGE_MIME_TYPES, true);
    }

    public function isPdf(): bool
    {
        return $this->mime === self::PDF_MIME_TYPE;
    }

    /**
     * @return array{source:string,filename:string,mime:string,size:int,base64:string,data_uri:string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'size' => $this->size,
            'base64' => $this->base64,
            'data_uri' => $this->data_uri,
        ];
    }

    /**
     * @param list<string> $allowedMimes
     */
    private static function fromDataUri(string $dataUri, array $allowedMimes, int|float|null $maxFileSizeMb): self
    {
        if (! preg_match('/^data:([^;,]+)(?:;[^,]*)*;base64,(.*)$/is', $dataUri, $matches)) {
            throw new InvalidArgumentException('Receipt data URI must include a MIME type and base64-encoded data.');
        }

        $mime = self::normalizeMime($matches[1]);
        self::assertSupportedMime($mime, $allowedMimes);

        $base64 = preg_replace('/\s+/', '', (string) $matches[2]) ?? '';
        if ($base64 === '') {
            throw new InvalidArgumentException('Receipt data URI contains no file data.');
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('Receipt data URI contains invalid base64 data.');
        }

        $size = strlen($bytes);
        self::assertNonEmpty($size);
        self::assertWithinSizeLimit($size, $maxFileSizeMb);

        return new self(
            'data_uri',
            'receipt.' . self::extensionForMime($mime),
            $mime,
            $bytes,
            $size,
            $base64,
            'data:' . $mime . ';base64,' . $base64,
        );
    }

    /**
     * @param list<string> $allowedMimes
     */
    private static function fromPath(
        string $path,
        array $allowedMimes,
        int|float|null $maxFileSizeMb,
        string $source,
        ?string $preferredFilename = null,
        mixed $sourceObject = null,
    ): self {
        if ($path === '') {
            throw new InvalidArgumentException('Receipt file path is empty.');
        }

        if (! is_file($path)) {
            throw new InvalidArgumentException('Receipt file does not exist: ' . basename($path));
        }

        if (! is_readable($path)) {
            throw new InvalidArgumentException('Receipt file is not readable: ' . basename($path));
        }

        $reportedSize = filesize($path);
        if ($reportedSize !== false) {
            self::assertNonEmpty((int) $reportedSize);
            self::assertWithinSizeLimit((int) $reportedSize, $maxFileSizeMb);
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new InvalidArgumentException('Unable to read receipt file: ' . basename($path));
        }

        $size = strlen($bytes);
        self::assertNonEmpty($size);
        self::assertWithinSizeLimit($size, $maxFileSizeMb);

        $mime = self::detectMime($bytes, $path, $allowedMimes, $sourceObject);
        self::assertSupportedMime($mime, $allowedMimes);

        $filename = self::sanitizeFilename($preferredFilename ?: basename($path), $mime);
        $base64 = base64_encode($bytes);

        return new self(
            $source,
            $filename,
            $mime,
            $bytes,
            $size,
            $base64,
            'data:' . $mime . ';base64,' . $base64,
        );
    }

    /**
     * @param list<string> $allowedMimes
     */
    private static function detectMime(string $bytes, string $path, array $allowedMimes, mixed $sourceObject = null): string
    {
        $candidates = [];

        if (is_object($sourceObject) && method_exists($sourceObject, 'getMimeType')) {
            $candidates[] = (string) $sourceObject->getMimeType();
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_buffer($finfo, $bytes);
            finfo_close($finfo);

            if (is_string($detected)) {
                $candidates[] = $detected;
            }
        }

        $pathDetected = @mime_content_type($path);
        if (is_string($pathDetected)) {
            $candidates[] = $pathDetected;
        }

        $extensionMime = self::mimeForExtension(pathinfo($path, PATHINFO_EXTENSION));
        if ($extensionMime !== null) {
            $candidates[] = $extensionMime;
        }

        $candidates = array_values(array_unique(array_filter(array_map(
            static fn (string $mime): string => self::normalizeMime($mime),
            $candidates,
        ))));

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $allowedMimes, true)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? 'application/octet-stream';
    }

    private static function isUploadedFileLike(object $input): bool
    {
        return method_exists($input, 'getRealPath')
            || method_exists($input, 'getPathname')
            || method_exists($input, 'getClientOriginalName');
    }

    private static function pathFromObject(object $input): string
    {
        if (method_exists($input, 'getRealPath')) {
            $path = $input->getRealPath();
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        if (method_exists($input, 'getPathname')) {
            $path = $input->getPathname();
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        throw new InvalidArgumentException('Unable to determine uploaded receipt file path.');
    }

    private static function looksLikeDataUri(string $input): bool
    {
        return str_starts_with(strtolower(ltrim($input)), 'data:');
    }

    /**
     * @param list<string> $allowedMimes
     */
    private static function assertSupportedMime(string $mime, array $allowedMimes): void
    {
        if (! in_array($mime, $allowedMimes, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported receipt file MIME type [%s]. Supported MIME types are: %s.',
                $mime,
                implode(', ', $allowedMimes),
            ));
        }
    }

    private static function assertNonEmpty(int $size): void
    {
        if ($size <= 0) {
            throw new InvalidArgumentException('Receipt file is empty.');
        }
    }

    private static function assertWithinSizeLimit(int $size, int|float|null $maxFileSizeMb): void
    {
        if ($maxFileSizeMb === null || $maxFileSizeMb <= 0) {
            return;
        }

        $limitBytes = (int) floor($maxFileSizeMb * 1024 * 1024);
        if ($size > $limitBytes) {
            throw new InvalidArgumentException(sprintf(
                'Receipt file exceeds the configured maximum size of %s MB.',
                rtrim(rtrim(number_format((float) $maxFileSizeMb, 2, '.', ''), '0'), '.'),
            ));
        }
    }

    /**
     * @param list<string> $allowedMimes
     * @return list<string>
     */
    private static function normalizeAllowedMimes(array $allowedMimes): array
    {
        if ($allowedMimes === []) {
            $allowedMimes = self::SUPPORTED_MIME_TYPES;
        }

        return array_values(array_unique(array_map(
            static fn (string $mime): string => self::normalizeMime($mime),
            $allowedMimes,
        )));
    }

    private static function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return match ($mime) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'application/x-pdf' => 'application/pdf',
            default => $mime,
        };
    }

    private static function sanitizeFilename(string $filename, string $mime): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: '';
        $filename = trim($filename, " \t\n\r\0\x0B.");

        if ($filename === '') {
            $filename = 'receipt.' . self::extensionForMime($mime);
        }

        if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $filename .= '.' . self::extensionForMime($mime);
        }

        return $filename;
    }

    private static function mimeForExtension(string $extension): ?string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg', 'jpe' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            default => null,
        };
    }

    private static function extensionForMime(string $mime): string
    {
        return match (self::normalizeMime($mime)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
