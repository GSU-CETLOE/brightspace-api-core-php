<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Model;

use Brightspace\Api\Core\Model\PagingInfo;
use Gadget\Exception\CastException;
use PHPUnit\Framework\TestCase;

final class PagingInfoTest extends TestCase
{
    public function testDefaultsToNoBookmarkAndNoMoreItems(): void
    {
        $info = new PagingInfo();

        self::assertNull($info->bookmark);
        self::assertFalse($info->hasMoreItems);
    }


    public function testCreatesFromTheBrightspacePagingInfoShape(): void
    {
        $info = PagingInfo::create(['Bookmark' => '12345', 'HasMoreItems' => true]);

        self::assertSame('12345', $info->bookmark);
        self::assertTrue($info->hasMoreItems);
    }


    public function testCreatesFromAnObjectAndCastsTheBookmarkToAString(): void
    {
        $info = PagingInfo::create((object) ['Bookmark' => 12345, 'HasMoreItems' => 'true']);

        self::assertSame('12345', $info->bookmark);
        self::assertTrue($info->hasMoreItems);
    }


    public function testAMissingBookmarkIsNull(): void
    {
        $info = PagingInfo::create(['HasMoreItems' => false]);

        self::assertNull($info->bookmark);
        self::assertFalse($info->hasMoreItems);
    }


    public function testHasMoreItemsIsRequired(): void
    {
        $this->expectException(CastException::class);

        PagingInfo::create(['Bookmark' => '1']);
    }


    public function testSomethingOtherThanAnArrayOrObjectIsRejected(): void
    {
        $this->expectException(CastException::class);

        PagingInfo::create(null);
    }
}
