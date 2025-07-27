<?php

declare(strict_types=1);

namespace Stream;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SplFileObject;
use Thenativeweb\Eventsourcingdb\Stream\FileUpload;

final class FileUploadTest extends TestCase
{
    private string $filePath;

    protected function setUp(): void
    {
        $this->filePath = tempnam(sys_get_temp_dir(), 'upload_');
        file_put_contents($this->filePath, "line1\nline2\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    public function testItConstructsSuccessfully(): void
    {
        $file = new SplFileObject($this->filePath, 'r');
        $fileUpload = new FileUpload($file);

        $this->assertInstanceOf(FileUpload::class, $fileUpload);
        $this->assertTrue($fileUpload->isReadable());
    }

    public function testItThrowsWhenFileIsNotReadable(): void
    {
        $file = new SplFileObject('php://memory', 'r');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be readable.');

        new FileUpload($file);
    }

    public function testItReturnsValidContentType(): void
    {
        $file = new SplFileObject($this->filePath, 'r');
        $fileUpload = new FileUpload($file, 'application/x-ndjson');

        $this->assertSame('application/x-ndjson', $fileUpload->getContentType());
    }

    public function testItThrowsOnUnsupportedContentType(): void
    {
        $file = new SplFileObject($this->filePath, 'r');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported content type');

        $fileUpload = new FileUpload($file, 'text/plain');
        $fileUpload->getContentType();
    }

    public function testItReadsFirstLine(): void
    {
        $file = new SplFileObject($this->filePath, 'r');
        $fileUpload = new FileUpload($file);

        $line = $fileUpload->read();
        $this->assertSame("line1\n", $line);
    }

    public function testItReturnsFileSize(): void
    {
        $file = new SplFileObject($this->filePath, 'r');
        $fileUpload = new FileUpload($file);

        $this->assertSame(filesize($this->filePath), $fileUpload->getSize());
    }

    public function testItReturnsRealPath(): void
    {
        $file = new SplFileObject($this->filePath, 'r');
        $fileUpload = new FileUpload($file);

        $this->assertSame(realpath($this->filePath), $fileUpload->getRealPath());
    }
}
