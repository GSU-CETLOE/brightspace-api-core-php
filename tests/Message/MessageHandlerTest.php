<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Message;

use Brightspace\Api\Core\Model\ProductVersion;
use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use Brightspace\Api\Core\Tests\Support\ExposedMessageHandler;
use GuzzleHttp\Psr7\Response;

final class MessageHandlerTest extends BrightspaceTestCase
{
    /**
     * Puts a valid token and a product version table in the cache so no login or lookup
     * round trip is needed to send an API request.
     *
     * @return void
     */
    private function seedTokenAndVersions(): void
    {
        $this->clientCache()->set('token', self::token());
        $this->clientCache()->set('productVersions', ['lp' => new ProductVersion('lp', '1.46')]);
    }


    public function testTheConfigAndCoreClientAreNotAvailableBeforeInit(): void
    {
        $handler = new ExposedMessageHandler('https://lms.example.edu/');

        try {
            $handler->exposeConfig();
            self::fail('Config should not be readable before init()');
        } catch (\RuntimeException) {
        }

        $this->expectException(\RuntimeException::class);
        $handler->exposeCoreClient();
    }


    public function testInitSetsTheConfigAndCoreClient(): void
    {
        $config = self::createConfig();
        $coreClient = $this->createCoreClient($this->createClient(), $config);
        $handler = new ExposedMessageHandler('https://lms.example.edu/');

        self::assertSame($handler, $handler->init($config, $coreClient));
        self::assertSame($config, $handler->exposeConfig());
        self::assertSame($coreClient, $handler->exposeCoreClient());
    }


    public function testSendsTheAccessTokenAsABearerHeaderByDefault(): void
    {
        $this->seedTokenAndVersions();
        $this->transport->append(new Response(200, [], 'ok'));
        $client = $this->createClient();
        $handler = (new ExposedMessageHandler('https://lms.example.edu/d2l/api/lp/1.46/users/whoami'))
            ->init(self::createConfig(), $this->createCoreClient($client));

        $handler->invoke($client);

        self::assertCount(1, $this->sentRequests);
        self::assertSame('Bearer the-access-token', $this->sentRequest()->getHeaderLine('Authorization'));
    }


    public function testCanSendWithoutAnAccessToken(): void
    {
        $this->transport->append(new Response(200, [], 'ok'));
        $client = $this->createClient();
        $handler = (new ExposedMessageHandler('https://lms.example.edu/d2l/home'))
            ->init(self::createConfig(), $this->createCoreClient($client))
            ->useAccessToken(false);

        $handler->invoke($client);

        self::assertCount(1, $this->sentRequests);
        self::assertFalse($this->sentRequest()->hasHeader('Authorization'));
    }


    public function testCanLogInToTheWebSessionBeforeSending(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home', self::sessionCookieHeaders()));
        $this->transport->append(new Response(200, [], 'the page'));
        $client = $this->createClient();
        $handler = (new ExposedMessageHandler('https://lms.example.edu/d2l/lp/some/page'))
            ->init(self::createConfig(), $this->createCoreClient($client))
            ->useAccessToken(false)
            ->useWebLogin(true);

        $response = $handler->invoke($client);

        self::assertSame('the page', (string) $response->getBody());
        self::assertSame('/d2l/lp/auth/login/login.d2l', $this->sentRequest(0)->getUri()->getPath());
        self::assertSame('/d2l/lp/some/page', $this->sentRequest(1)->getUri()->getPath());
        $cookies = $this->sentRequest(1)->getHeaderLine('Cookie');
        self::assertStringContainsString('d2lSessionVal=session-value', $cookies);
    }


    public function testWebLoginIsSkippedWhenTheSessionIsStillValid(): void
    {
        $this->transport->append(new Response(200, [], 'the page'));
        $client = $this->createClient();
        self::markLoggedIn($client);
        $handler = (new ExposedMessageHandler('https://lms.example.edu/d2l/lp/some/page'))
            ->init(self::createConfig(), $this->createCoreClient($client))
            ->useAccessToken(false)
            ->useWebLogin(true);

        $handler->invoke($client);

        self::assertCount(1, $this->sentRequests);
        self::assertSame('/d2l/lp/some/page', $this->sentRequest()->getUri()->getPath());
    }


    public function testRewritesAD2lUriToTheVersionedApiPathOnTheConfiguredHost(): void
    {
        $this->seedTokenAndVersions();
        $this->transport->append(new Response(200, [], 'ok'));
        $client = $this->createClient();
        $handler = (new ExposedMessageHandler('d2l://lp/users/whoami'))
            ->init(self::createConfig(), $this->createCoreClient($client));

        $handler->invoke($client);

        self::assertSame(
            'https://lms.example.edu/d2l/api/lp/1.46/users/whoami',
            (string) $this->sentRequest()->getUri()
        );
    }


    public function testKeepsTheQueryStringWhenRewriting(): void
    {
        $this->seedTokenAndVersions();
        $this->transport->append(new Response(200, [], 'ok'));
        $client = $this->createClient();
        $handler = (new ExposedMessageHandler('d2l://lp/users/?bookmark=5'))
            ->init(self::createConfig(), $this->createCoreClient($client));

        $handler->invoke($client);

        self::assertSame(
            'https://lms.example.edu/d2l/api/lp/1.46/users/?bookmark=5',
            (string) $this->sentRequest()->getUri()
        );
    }


    /** @return iterable<string,array{string,string}> */
    public static function unversionedUris(): iterable
    {
        yield 'api host keeps the path as given' => [
            'd2l://api/d2l/api/versions/',
            'https://lms.example.edu/d2l/api/versions/',
        ];
        yield 'web host keeps the path as given' => [
            'd2l://web/d2l/home',
            'https://lms.example.edu/d2l/home',
        ];
        yield 'a path already under /d2l/api/ is not prefixed' => [
            'd2l://lp/d2l/api/lp/1.0/users/',
            'https://lms.example.edu/d2l/api/lp/1.0/users/',
        ];
    }


    /**
     * @dataProvider unversionedUris
     * @param string $uri
     * @param string $expected
     */
    public function testOnlyProductHostsGetTheVersionedPrefix(string $uri, string $expected): void
    {
        $this->seedTokenAndVersions();
        $this->transport->append(new Response(200, [], 'ok'));
        $client = $this->createClient();
        $handler = (new ExposedMessageHandler($uri))
            ->init(self::createConfig(), $this->createCoreClient($client));

        $handler->invoke($client);

        self::assertSame($expected, (string) $this->sentRequest()->getUri());
    }


    public function testLeavesOtherSchemesAlone(): void
    {
        $this->seedTokenAndVersions();
        $this->transport->append(new Response(200, [], 'ok'));
        $client = $this->createClient();
        $handler = (new ExposedMessageHandler('https://other.example.edu/d2l/api/lp/1.0/users/'))
            ->init(self::createConfig(), $this->createCoreClient($client));

        $handler->invoke($client);

        self::assertSame(
            'https://other.example.edu/d2l/api/lp/1.0/users/',
            (string) $this->sentRequest()->getUri()
        );
    }


    public function testLooksUpTheProductVersionWhenItIsNotCached(): void
    {
        $this->clientCache()->set('token', self::token());
        $this->transport->append(self::productVersionsResponse(['lp' => '1.46']));
        $this->transport->append(new Response(200, [], 'ok'));
        $client = $this->createClient();
        $handler = (new ExposedMessageHandler('d2l://lp/users/whoami'))
            ->init(self::createConfig(), $this->createCoreClient($client));

        $handler->invoke($client);

        self::assertSame('https://lms.example.edu/d2l/api/versions/', (string) $this->sentRequest(0)->getUri());
        self::assertSame(
            'https://lms.example.edu/d2l/api/lp/1.46/users/whoami',
            (string) $this->sentRequest(1)->getUri()
        );
    }
}
