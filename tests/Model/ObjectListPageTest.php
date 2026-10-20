<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Model;

use Brightspace\Api\Core\Model\ObjectListPage;
use Gadget\Io\Cast;
use PHPUnit\Framework\TestCase;

final class ObjectListPageTest extends TestCase
{
    public function testCreatesFromTheBrightspaceObjectListPageShape(): void
    {
        $page = ObjectListPage::create(
            [
                'PagingInfo' => 'https://lms.example.edu/d2l/api/lp/1.46/users/?bookmark=2',
                'Objects' => [['Id' => 1], ['Id' => 2]],
            ],
            fn(mixed $v): int => Cast::toInt(Cast::toArray($v)['Id'] ?? null)
        );

        self::assertSame('https://lms.example.edu/d2l/api/lp/1.46/users/?bookmark=2', $page->next);
        self::assertSame([1, 2], $page->objects);
    }


    public function testMissingFieldsMeanALastEmptyPage(): void
    {
        $page = ObjectListPage::create([], fn(mixed $v): mixed => $v);

        self::assertNull($page->next);
        self::assertSame([], $page->objects);
    }


    public function testForEachFollowsNextUntilItRunsOut(): void
    {
        $pages = [
            null => new ObjectListPage('page2', ['a', 'b']),
            'page2' => new ObjectListPage('page3', ['c']),
            'page3' => new ObjectListPage(null, ['d']),
        ];
        $requested = [];

        $objects = ObjectListPage::forEach(function (string|null $next) use ($pages, &$requested): ObjectListPage {
            $requested[] = $next;
            return $pages[$next];
        });

        self::assertSame(['a', 'b', 'c', 'd'], [...$objects]);
        self::assertSame([null, 'page2', 'page3'], $requested);
    }


    public function testForEachStopsOnAnEmptyNext(): void
    {
        $objects = ObjectListPage::forEach(
            fn(string|null $next): ObjectListPage => new ObjectListPage('', ['only'])
        );

        self::assertSame(['only'], [...$objects]);
    }
}
