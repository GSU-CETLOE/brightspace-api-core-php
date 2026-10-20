<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Model;

use Gadget\Io\Cast;

/**
 * @template T
 */
final class ObjectListPage
{
    /**
     * @template TValue
     * @param (callable(string|null $next): self<TValue>) $fetchNext
     * @return iterable<TValue>
     */
    public static function forEach(callable $fetchNext): iterable
    {
        $next = null;
        do {
            $page = $fetchNext($next);
            yield from $page->objects;
            $next = $page->next;
        } while ($next !== null && $next !== '');
    }


    /**
     * @param mixed $values
     * @param (callable(mixed $v): T) $toValue
     * @return self<T>
     */
    public static function create(
        mixed $values,
        callable $toValue
    ): self {
        $values = Cast::toArray($values);
        return new self(
            next: Cast::toValueOrNull($values['PagingInfo'] ?? null, Cast::toString(...)),
            objects: Cast::toTypedArray($values['Objects'] ?? [], $toValue)
        );
    }


    /**
     * @param string|null $next
     * @param T[] $objects
     */
    public function __construct(
        public string|null $next = null,
        public array $objects = []
    ) {
    }
}
