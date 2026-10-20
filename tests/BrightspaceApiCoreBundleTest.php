<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests;

use Brightspace\Api\Core\BrightspaceApiCoreBundle;
use Brightspace\Api\Core\DependencyInjection\BrightspaceApiCoreExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class BrightspaceApiCoreBundleTest extends TestCase
{
    public function testIsASymfonyBundle(): void
    {
        self::assertInstanceOf(Bundle::class, new BrightspaceApiCoreBundle());
    }


    public function testExposesTheBrightspaceApiCoreExtension(): void
    {
        $extension = (new BrightspaceApiCoreBundle())->getContainerExtension();

        self::assertInstanceOf(BrightspaceApiCoreExtension::class, $extension);
        self::assertSame('brightspace_api_core', $extension->getAlias());
    }
}
