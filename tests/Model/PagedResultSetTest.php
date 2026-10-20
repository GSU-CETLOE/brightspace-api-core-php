<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Model;

use Brightspace\Api\Core\Model\PagedResultSet;
use Brightspace\Api\Core\Model\PagingInfo;
use Gadget\Exception\CastException;
use Gadget\Io\Cast;
use PHPUnit\Framework\TestCase;

final class PagedResultSetTest extends TestCase
{
    public function testCreatesFromTheBrightspacePagedResultSetShape(): void
    {
        $page = PagedResultSet::create(
            [
                'PagingInfo' => ['Bookmark' => 'b2', 'HasMoreItems' => true],
                'Items' => [['Id' => 1], ['Id' => 2]],
            ],
            fn(mixed $v): int => Cast::toInt(Cast::toArray($v)['Id'] ?? null)
        );

        self::assertSame('b2', $page->pagingInfo->bookmark);
        self::assertTrue($page->pagingInfo->hasMoreItems);
        self::assertSame([1, 2], $page->items);
    }


    public function testMissingItemsMeanAnEmptyPage(): void
    {
        $page = PagedResultSet::create(['PagingInfo' => ['HasMoreItems' => false]], fn(mixed $v): mixed => $v);

        self::assertNull($page->pagingInfo->bookmark);
        self::assertSame([], $page->items);
    }


    public function testPagingInfoIsRequired(): void
    {
        $this->expectException(CastException::class);

        PagedResultSet::create(['Items' => []], fn(mixed $v): mixed => $v);
    }


    public function testForEachWalksEveryPageUntilTheBookmarkRunsOut(): void
    {
        $pages = [
            null => new PagedResultSet(new PagingInfo('b2', true), ['a', 'b']),
            'b2' => new PagedResultSet(new PagingInfo('b3', true), ['c']),
            'b3' => new PagedResultSet(new PagingInfo(null, false), ['d']),
        ];
        $requested = [];

        $items = PagedResultSet::forEach(function (string|null $bookmark) use ($pages, &$requested): PagedResultSet {
            $requested[] = $bookmark;
            return $pages[$bookmark];
        });

        self::assertSame(['a', 'b', 'c', 'd'], [...$items]);
        self::assertSame([null, 'b2', 'b3'], $requested);
    }


    public function testForEachStopsOnAnEmptyBookmark(): void
    {
        $items = PagedResultSet::forEach(
            fn(string|null $bookmark): PagedResultSet => new PagedResultSet(new PagingInfo('', false), ['only'])
        );

        self::assertSame(['only'], [...$items]);
    }
}
