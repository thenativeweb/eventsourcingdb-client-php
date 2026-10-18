<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Api;

use DateTimeImmutable;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\CloudEvent;
use Thenativeweb\Eventsourcingdb\DbApiException;
use Thenativeweb\Eventsourcingdb\Stream\RawJson;
use Thenativeweb\Eventsourcingdb\Stream\Response;

/**
 * Reads what both APIs of EventSourcingDB answer alike: whether a response
 * comes from EventSourcingDB at all, the errors it reports, and the events
 * it sends.
 *
 * @internal
 */
final class Responses
{
    public static function throwIfNotFromEventSourcingDb(Response $response): void
    {
        $serverHeader = $response->getHeader('Server');

        if ($serverHeader === []) {
            $response->getStream()
                ->close();

            throw new RuntimeException('Server Header is empty.');
        }

        if (!str_starts_with($serverHeader[0], 'EventSourcingDB/')) {
            $response->getStream()
                ->close();

            throw new RuntimeException('Server must be EventSourcingDB.');
        }
    }

    /**
     * Creates the error of a response whose status is not 200, which carries
     * the description as plain text, and, for a v1 error that clearly matches
     * an error of the v2 API, the code of that error.
     */
    public static function createErrorFromStatus(Response $response, string $scope, string $code = ''): DbApiException
    {
        $description = trim($response->getStream()->getContents());

        return self::createError($response, $scope, $code, $description);
    }

    /**
     * Creates the error of a response whose status is not 200 from its
     * description, which the caller has read already.
     */
    public static function createError(Response $response, string $scope, string $code, string $description): DbApiException
    {
        $status = $response->getStatusCode();

        return new DbApiException(
            message: self::createMessage($scope, $description, $status),
            code: $code,
            description: $description,
            details: [],
            status: $status,
            retryAfter: $status === 429 ? self::getRetryAfter($response) : null,
        );
    }

    /**
     * Creates the error that the v2 API reports in the body of a response,
     * as the data of an item of type error.
     */
    public static function createErrorFromItem(mixed $data, string $scope): DbApiException
    {
        $data = is_array($data) ? $data : [];
        $code = is_string($data['code'] ?? null) ? $data['code'] : '';
        $description = is_string($data['description'] ?? null) ? $data['description'] : '';
        $details = is_array($data['details'] ?? null) ? $data['details'] : [];

        return new DbApiException(
            message: self::createMessage($scope, $description, 200),
            code: $code,
            description: $description,
            details: $details,
            status: 200,
        );
    }

    /**
     * Creates the error that the v1 API reports within a stream, which has a
     * description, but no code.
     */
    public static function createErrorFromV1Item(mixed $payload, string $scope): DbApiException
    {
        $description = is_array($payload) && is_string($payload['error'] ?? null) ? $payload['error'] : 'unknown error';

        return new DbApiException(
            message: self::createMessage($scope, $description, 200),
            code: '',
            description: $description,
            details: [],
            status: 200,
        );
    }

    /**
     * Creates an event from the raw JSON object that the server sent for it,
     * and keeps its data as the server sent it, so that its hash can be
     * verified.
     */
    public static function createCloudEvent(array $event, ?string $rawEvent): CloudEvent
    {
        return new CloudEvent(
            $event['specversion'],
            $event['id'],
            new DateTimeImmutable($event['time']),
            $event['time'],
            $event['source'],
            $event['subject'],
            $event['type'],
            $event['datacontenttype'],
            $event['data'],
            $event['hash'],
            $event['predecessorhash'],
            $event['traceparent'] ?? null,
            $event['tracestate'] ?? null,
            $event['signature'] ?? null,
            $rawEvent === null ? null : (RawJson::getMembers($rawEvent)['data'] ?? null),
        );
    }

    /**
     * Returns the seconds of the Retry-After header, if it names them, which
     * EventSourcingDB does, rather than a date.
     */
    private static function getRetryAfter(Response $response): ?int
    {
        $retryAfter = $response->getHeader('Retry-After');
        if ($retryAfter === [] || preg_match('/^\d+$/', $retryAfter[0]) !== 1) {
            return null;
        }

        return (int) $retryAfter[0];
    }

    private static function createMessage(string $scope, string $description, int $status): string
    {
        if ($description === '') {
            return "{$scope}, the server answered with status {$status}.";
        }

        return "{$scope}, {$description}";
    }
}
