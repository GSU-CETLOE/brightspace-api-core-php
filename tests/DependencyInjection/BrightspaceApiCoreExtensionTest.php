<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\DependencyInjection;

use Brightspace\Api\Core\Client\ApiClient;
use Brightspace\Api\Core\Client\CoreApiClient;
use Brightspace\Api\Core\Client\CoreClient;
use Brightspace\Api\Core\Config;
use Brightspace\Api\Core\DependencyInjection\BrightspaceApiCoreExtension;
use Gadget\Cache\CacheItemPool;
use Gadget\Http\DependencyInjection\GadgetHttpExtension;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BrightspaceApiCoreExtensionTest extends TestCase
{
    private static function loadedContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new BrightspaceApiCoreExtension())->load([], $container);
        return $container;
    }


    /** @return iterable<string,array{class-string}> */
    public static function services(): iterable
    {
        yield 'Config' => [Config::class];
        yield 'ApiClient' => [ApiClient::class];
        yield 'CoreApiClient' => [CoreApiClient::class];
        yield 'CoreClient' => [CoreClient::class];
    }


    /**
     * @dataProvider services
     * @param class-string $service
     */
    public function testRegistersAnAutowiredService(string $service): void
    {
        $container = self::loadedContainer();

        self::assertTrue($container->hasDefinition($service));
        $definition = $container->getDefinition($service);
        self::assertTrue($definition->isAutowired());
        self::assertTrue($definition->isAutoconfigured());
    }


    public function testBindsTheConfigToTheD2lEnvironmentVariables(): void
    {
        $arguments = self::loadedContainer()->getDefinition(Config::class)->getArguments();

        self::assertSame([
            '$d2lHost' => '%env(string:default::D2L_HOST)%',
            '$rootOrgUnit' => '%env(int:default::D2L_ROOT_ORGUNIT)%',
            '$clientId' => '%env(string:default::D2L_CLIENT_ID)%',
            '$clientSecret' => '%env(string:default::D2L_CLIENT_SECRET)%',
            '$redirectUri' => '%env(string:default::D2L_REDIRECT_URI)%',
            '$scope' => '%env(string:default::D2L_SCOPE)%',
            '$username' => '%env(string:default::D2L_USERNAME)%',
            '$password' => '%env(string:default::D2L_PASSWORD)%',
            '$mfa' => '%env(string:default::D2L_MFA_KEY)%',
        ], $arguments);
    }


    public function testCompiledContainerBuildsTheClientsOnTopOfTheHttpBundle(): void
    {
        $container = self::loadedContainer();
        (new GadgetHttpExtension())->load([], $container);
        $container->register(ClientInterface::class, GuzzleClient::class);
        $container->register(HttpFactory::class, HttpFactory::class);
        foreach (
            [
                RequestFactoryInterface::class,
                ResponseFactoryInterface::class,
                ServerRequestFactoryInterface::class,
                StreamFactoryInterface::class,
                UploadedFileFactoryInterface::class,
                UriFactoryInterface::class,
            ] as $factoryInterface
        ) {
            $container->setAlias($factoryInterface, HttpFactory::class);
        }
        $container->register(CacheItemPoolInterface::class, ArrayAdapter::class);
        $container->register(CacheItemPool::class, CacheItemPool::class)->setAutowired(true);
        foreach (
            [
                'D2L_HOST' => 'lms.example.edu',
                'D2L_ROOT_ORGUNIT' => '6606',
                'D2L_CLIENT_ID' => 'client-id',
                'D2L_CLIENT_SECRET' => 'client-secret',
                'D2L_REDIRECT_URI' => 'https://app.example.edu/callback',
                'D2L_SCOPE' => 'core:*:*',
                'D2L_USERNAME' => 'svc-user',
                'D2L_PASSWORD' => 'secret',
                'D2L_MFA_KEY' => '',
            ] as $name => $value
        ) {
            $container->setParameter("env({$name})", $value);
        }
        $container->getDefinition(Config::class)->setPublic(true);
        $container->getDefinition(CoreClient::class)->setPublic(true);
        $container->getDefinition(ApiClient::class)->setPublic(true);

        $container->compile(true);

        $config = $container->get(Config::class);
        self::assertInstanceOf(Config::class, $config);
        self::assertSame('lms.example.edu', $config->d2lHost);
        self::assertSame(6606, $config->rootOrgUnit);
        self::assertNull($config->mfa);
        self::assertInstanceOf(CoreClient::class, $container->get(CoreClient::class));
        self::assertInstanceOf(ApiClient::class, $container->get(ApiClient::class));
    }
}
