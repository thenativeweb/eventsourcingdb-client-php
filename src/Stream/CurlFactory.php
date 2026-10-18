<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Stream;

use CurlHandle;

/**
 * @internal
 *
 * @see \Thenativeweb\Eventsourcingdb\Tests\Stream\CurlFactoryTest
 */
class CurlFactory
{
    public static function create(
        Request $request,
        Queue $queueHeader,
        Queue $queueWrite,
        int $timeout = 0,
        ?StallClock $stallClock = null,
    ): array {
        $stallClock ??= new StallClock(CurlMultiHandler::DEFAULT_STALL_TIMEOUT);

        $httpVersion = match ($request->getProtocolVersion()) {
            '2.0', '2' => CURL_HTTP_VERSION_2_0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            default => CURL_HTTP_VERSION_1_0,
        };

        $options = [
            // The stall timeout bounds connecting as well, and the handler
            // enforces it itself, so the connect timeout of cURL lies beyond
            // it, rather than at its default of 300 seconds, which may come
            // first.
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(ceil($stallClock->getStallTimeout() * 1000) + 1000, PHP_INT_MAX),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $request->getHeaders(),
            CURLOPT_HTTP_VERSION => $httpVersion,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_URL => (string) $request->getUri(),
            CURLOPT_VERBOSE => false,
        ];

        $contentType = null;
        $options[CURLOPT_HEADERFUNCTION] = function (?CurlHandle $curlHandle, string $header) use (&$queueHeader, &$contentType, $stallClock): int {
            $stallClock->reset();

            // cURL also hands over the empty line that ends the headers.
            if (trim($header) === '') {
                $queueHeader->complete();

                return strlen($header);
            }

            $queueHeader->write($header);

            if (preg_match('/^Content-Type:\s*(.+)$/i', $header, $matches)) {
                $contentType = strtolower(trim($matches[1]));
            }

            return strlen($header);
        };

        $buffer = '';
        $options[CURLOPT_WRITEFUNCTION] = function (?CurlHandle $curlHandle, string $chunk) use (&$buffer, &$queueWrite, &$contentType, $stallClock): int {
            // Every chunk resets the stall clock, even one that does not
            // complete a line yet.
            $stallClock->reset();

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

        if ($timeout !== 0) {
            $options[CURLOPT_TIMEOUT] = $timeout;
        }

        if ($request->getBody() !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->getBody();
        }

        if ($request->hasHeader('Accept-Encoding')) {
            $options[CURLOPT_ENCODING] = implode(',', $request->getHeader('Accept-Encoding'));
        }

        if ($request->getMethod() === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
            unset(
                $options[CURLOPT_WRITEFUNCTION],
            );
        }

        return $options;
    }
}
