<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use CurlHandle;

class CurlFactory
{
    public static function create(
        Request $request,
        Queue $queueHeader,
        Queue $queueWrite,
        int $timeout = 0,
    ): array {
        $httpVersion = match ($request->getProtocolVersion()) {
            '2.0', '2' => CURL_HTTP_VERSION_2_0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            default => CURL_HTTP_VERSION_1_0,
        };

        $options = [
            CURLOPT_CONNECTTIMEOUT => 300,
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $request->getHeaders(),
            CURLOPT_HTTP_VERSION => $httpVersion,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_VERBOSE => false,
        ];

        $contentType = null;
        $options[CURLOPT_HEADERFUNCTION] = function (?CurlHandle $curlHandle, string $header) use (&$queueHeader, &$contentType): int {
            $queueHeader->write($header);

            if (preg_match('/^Content-Type:\s*(.+)$/i', $header, $matches)) {
                $contentType = strtolower(trim($matches[1]));
            }

            return strlen($header);
        };

        $buffer = '';
        $options[CURLOPT_WRITEFUNCTION] = function (?CurlHandle $curlHandle, string $chunk) use (&$buffer, &$queueWrite, &$contentType): int {
            $buffer .= $chunk;
            $write = true;

            if ($contentType === 'application/x-ndjson' && !str_ends_with($buffer, "\n")) {
                $write = false;
            }

            if ($write) {
                $queueWrite->write($buffer);
                $buffer = '';
            }

            return strlen($chunk);
        };

        if ($request->getUri()->getScheme() === 'https') {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = false;
        }

        if ($timeout !== 0) {
            $options[CURLOPT_TIMEOUT] = $timeout;
        }

        if (is_string($request->getBody())) {
            $options[CURLOPT_POSTFIELDS] = $request->getBody();
        }

        if ($request->getBody() instanceof FileUpload) {
            $fileUpload = $request->getBody();

            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_RETURNTRANSFER] = true;
            $options[CURLOPT_INFILESIZE] = $fileUpload->getSize();
            $options[CURLOPT_READFUNCTION] = function () use ($fileUpload): string {
                return $fileUpload->read();
            };
        }

        if ($request->hasHeader('Accept-Encoding')) {
            $options[CURLOPT_ENCODING] = implode(',', $request->getHeader('Accept-Encoding'));
        }

        if ($request->getMethod() === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
            unset(
                $options[CURLOPT_UPLOAD],
                $options[CURLOPT_INFILESIZE],
                $options[CURLOPT_READFUNCTION],
                $options[CURLOPT_WRITEFUNCTION],
            );
        }

        return $options;
    }
}
