<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Model;

use Brightspace\Api\Core\Model\ProductVersion;
use PHPUnit\Framework\TestCase;

final class ProductVersionTest extends TestCase
{
    public function testKeepsTheProductCodeAndLatestVersion(): void
    {
        $version = new ProductVersion(ProductCode: 'lp', LatestVersion: '1.46');

        self::assertSame('lp', $version->ProductCode);
        self::assertSame('1.46', $version->LatestVersion);
    }
}
