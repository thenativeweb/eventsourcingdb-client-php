<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb\Tests\Stream;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thenativeweb\Eventsourcingdb\Stream\RawJson;

final class RawJsonTest extends TestCase
{
    public static function malformedObjects(): Iterator
    {
        yield 'not an object' => ['[]'];
        yield 'a string that does not end' => ['{"a":"b'];
        yield 'an object that does not end' => ['{"a":{"b":1}'];
        yield 'a member without a value' => ['{"a":}'];
        yield 'a member without a colon' => ['{"a" 1}'];
    }

    public function testGetMembersReturnsEveryMemberAsItIsWritten(): void
    {
        $members = RawJson::getMembers('{"string":"a b","number":-1.50e+3,"true":true,"false":false,"null":null,"object":{"b":[1,{"c":"]}"}]},"array":[ 1 , 2 ]}');

        $this->assertSame([
            'string' => '"a b"',
            'number' => '-1.50e+3',
            'true' => 'true',
            'false' => 'false',
            'null' => 'null',
            'object' => '{"b":[1,{"c":"]}"}]}',
            'array' => '[ 1 , 2 ]',
        ], $members);
    }

    public function testGetMembersSkipsWhitespaceAroundMembers(): void
    {
        $members = RawJson::getMembers(" \n{ \"a\" :\t{ \"b\" : 1 } ,\r\n\"c\" : \"d\" }\n");

        $this->assertSame([
            'a' => '{ "b" : 1 }',
            'c' => '"d"',
        ], $members);
    }

    public function testGetMembersKeepsEscapedCharactersAsTheyAreWritten(): void
    {
        $members = RawJson::getMembers('{"a\"b":"c\\\\","d":{"e":"\"}"},"f":"/"}');

        $this->assertSame([
            'a"b' => '"c\\\\"',
            'd' => '{"e":"\"}"}',
            'f' => '"/"',
        ], $members);
    }

    public function testGetMembersKeepsCharactersBeyondAsciiAsTheyAreWritten(): void
    {
        $members = RawJson::getMembers('{"title":"2001 – A Space Odyssey"}');

        $this->assertSame([
            'title' => '"2001 – A Space Odyssey"',
        ], $members);
    }

    public function testGetMembersReturnsNothingForAnEmptyObject(): void
    {
        $this->assertSame([], RawJson::getMembers('{ }'));
    }

    #[DataProvider('malformedObjects')]
    public function testGetMembersThrowsForMalformedJson(string $json): void
    {
        $this->expectException(RuntimeException::class);

        RawJson::getMembers($json);
    }

    public function testGetElementsReturnsEveryElementAsItIsWritten(): void
    {
        $elements = RawJson::getElements('[{"a":[1,2]}, "b" ,3,[],{}]');

        $this->assertSame(['{"a":[1,2]}', '"b"', '3', '[]', '{}'], $elements);
    }

    public function testGetElementsReturnsNothingForAnEmptyArray(): void
    {
        $this->assertSame([], RawJson::getElements("[\n]"));
    }

    public function testGetElementsThrowsIfTheJsonIsNotAnArray(): void
    {
        $this->expectException(RuntimeException::class);

        RawJson::getElements('{}');
    }
}
