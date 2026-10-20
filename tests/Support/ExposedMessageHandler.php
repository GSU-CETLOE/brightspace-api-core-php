<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Support;

use Brightspace\Api\Core\Client\CoreClient;
use Brightspace\Api\Core\Config;
use Brightspace\Api\Core\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A concrete Brightspace MessageHandler that sends one request to a URI of the test's choosing
 * and hands back the raw response, with the protected accessors exposed.
 *
 * @extends MessageHandler<ResponseInterface>
 */
final class ExposedMessageHandler extends MessageHandler
{
    /**
     * @param string $uri
     * @param string $method
     */
    public function __construct(
        private string $uri,
        private string $method = 'GET'
    ) {
    }


    /**
     * @return Config
     */
    public function exposeConfig(): Config
    {
        return $this->getConfig();
    }


    /**
     * @return CoreClient
     */
    public function exposeCoreClient(): CoreClient
    {
        return $this->getCoreClient();
    }


    /**
     * @param RequestBuilder $requestBuilder
     * @return ServerRequestInterface
     */
    protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
    {
        return $requestBuilder
            ->setMethod($this->method)
            ->setUri($this->uri)
            ->getRequest();
    }


    /**
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function handleResponse(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): mixed {
        return $response;
    }
}
