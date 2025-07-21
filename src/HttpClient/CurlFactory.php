<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\HttpClient;

class CurlFactory
{
    public static function create(
        RequestInterface $request,
        BufferQueue $bufferQueueHeader,
        BufferQueue $bufferQueueWrite,
        int $timeout = 0,
    ): array
    {
        $httpVersion = match($request->getProtocolVersion()) {
            '2,0', '2.0', '2' => \CURL_HTTP_VERSION_2_0,
            '1,1', '1.1' => \CURL_HTTP_VERSION_1_1,
            default => \CURL_HTTP_VERSION_1_0,
        };

        $options = [
            \CURLOPT_CONNECTTIMEOUT => 300,
            \CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            \CURLOPT_HEADER => false,
            \CURLOPT_HTTPHEADER => $request->getHeaders(),
            \CURLOPT_HTTP_VERSION => $httpVersion,
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_URL => (string) $request->getUri(),
            \CURLOPT_VERBOSE => false,
        ];

        $options[\CURLOPT_HEADERFUNCTION] = function ($ch, $header) use (&$bufferQueueHeader) {
            $bufferQueueHeader->write($header);
            return strlen($header);
        };
        $options[\CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) use (&$bufferQueueWrite) {
            $bufferQueueWrite->write($chunk);
            return strlen($chunk);
        };

        if ($request->getUri()->getScheme() === 'https') {
            $options[\CURLOPT_SSL_VERIFYPEER] = false;
            $options[\CURLOPT_SSL_VERIFYHOST] = false;
        }

        if ($timeout !== 0) {
            $options[\CURLOPT_TIMEOUT] = $timeout;
        }

        if ($request->getBody() !== null) {
            $options[\CURLOPT_POSTFIELDS] = $request->getBody();
        }

        if ($request->getMethod() === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
            unset(
                $options[\CURLOPT_WRITEFUNCTION],
                $options[\CURLOPT_READFUNCTION],
                $options[\CURLOPT_FILE],
                $options[\CURLOPT_INFILE]
            );
        }

        return $options;
    }
}
