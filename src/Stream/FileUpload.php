<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use InvalidArgumentException;
use SplFileObject;

class FileUpload
{
    private const SUPPORTED_CONTENT_TYPES = [
        'application/x-ndjson',
    ];

    public function __construct(
        private readonly SplFileObject $splFileObject,
        private readonly string $contentType = 'application/x-ndjson',
    ) {
        if (!$splFileObject->isReadable()) {
            throw new InvalidArgumentException("The file {$this->splFileObject->getRealPath()} must be readable.");
        }
    }

    public function getContentType(): string
    {
        if (!in_array($this->contentType, self::SUPPORTED_CONTENT_TYPES, true)) {
            $supportedContentTypes = implode("', '", self::SUPPORTED_CONTENT_TYPES);
            throw new InvalidArgumentException(
                "Unsupported content type: '{$this->contentType}', expected '{$supportedContentTypes}'."
            );
        }

        return $this->contentType;
    }

    public function isReadable(): bool
    {
        return $this->splFileObject->isReadable();
    }

    public function getRealPath(): string
    {
        return $this->splFileObject->getRealPath();
    }

    public function getSize(): int
    {
        return $this->splFileObject->getSize();
    }

    public function read(): string
    {
        return $this->splFileObject->fgets();
    }
}
