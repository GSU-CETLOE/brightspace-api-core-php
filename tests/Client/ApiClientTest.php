<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Client;

use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use Brightspace\Api\Core\Tests\Support\ExposedApiClient;
use Brightspace\Api\Core\Tests\Support\ExposedMessageHandler;
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiClientTest extends BrightspaceTestCase
{
    public function testExposesTheConfigAndCoreClientItWasBuiltWith(): void
    {
        $client = $this->createClient();
        $config = self::createConfig();
        $coreClient = $this->createCoreClient($client, $config);

        $api = new ExposedApiClient($client, $config, $coreClient);

        self::assertSame($config, $api->exposeConfig());
        self::assertSame($coreClient, $api->exposeCoreClient());
    }


    public function testInvokeInitializesABrightspaceHandlerBeforeSendingIt(): void
    {
        $this->transport->append(new Response(200, [], 'ok'));
        $client = $this->createClient();
        $config = self::createConfig();
        $coreClient = $this->createCoreClient($client, $config);
        $api = new ExposedApiClient($client, $config, $coreClient);
        $handler = (new ExposedMessageHandler('https://lms.example.edu/d2l/home'))->useAccessToken(false);

        $response = $api->exposeInvoke($handler);

        self::assertSame('ok', (string) $response->getBody());
        self::assertSame($config, $handler->exposeConfig());
        self::assertSame($coreClient, $handler->exposeCoreClient());
        self::assertSame('https://lms.example.edu/d2l/home', (string) $this->sentRequest()->getUri());
    }


    public function testInvokeStillRunsAPlainGadgetHandler(): void
    {
        $this->transport->append(new Response(200, [], 'pong'));
        $client = $this->createClient();
        $api = new ExposedApiClient($client, self::createConfig(), $this->createCoreClient($client));

        $handler = new class extends MessageHandler {
            protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
            {
                return $requestBuilder->setUri('https://lms.example.edu/ping')->getRequest();
            }

            protected function handleResponse(
                ResponseInterface $response,
                ServerRequestInterface $request
            ): mixed {
                return (string) $response->getBody();
            }
        };

        self::assertSame('pong', $api->exposeInvoke($handler));
        self::assertFalse($this->sentRequest()->hasHeader('Authorization'));
    }
}
