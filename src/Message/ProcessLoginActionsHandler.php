<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Message;

use Brightspace\Api\Core\Model\LoginUri;
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @extends MessageHandler<LoginUri|null> */
class ProcessLoginActionsHandler extends MessageHandler
{
    /**
     * @param string $d2lHost
     */
    public function __construct(private string $d2lHost)
    {
    }


    /**
     * @return ServerRequestInterface
     */
    protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
    {
        return $requestBuilder
            ->setMethod('GET')
            ->setUri('https://' . $this->d2lHost . LoginUri::PROCESS_LOGIN->value)
            ->getRequest();
    }


    /**
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return LoginUri|null
     */
    protected function handleResponse(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): mixed {
        return LoginUri::tryFrom(
            ($response->getStatusCode() === 302)
                ? ($response->getHeader('Location')[0] ?? '')
                : ''
        );
    }
}
