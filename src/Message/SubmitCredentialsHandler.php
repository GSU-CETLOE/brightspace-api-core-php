<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Message;

use Brightspace\Api\Core\Model\LoginUri;
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @extends MessageHandler<LoginUri|null> */
class SubmitCredentialsHandler extends MessageHandler
{
    /**
     * @param string $d2lHost
     * @param string $username
     * @param string $password
     */
    public function __construct(
        private string $d2lHost,
        private string $username,
        private string $password
    ) {
    }


    /**
     * @param RequestBuilder $requestBuilder
     * @return ServerRequestInterface
     */
    protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
    {
        return $requestBuilder
            ->setMethod('POST')
            ->setUri('https://' . $this->d2lHost . LoginUri::LOGIN->value)
            ->setBody(
                'application/x-www-form-urlencoded',
                [
                    'd2l_referrer' => '',
                    'noredirect'   => '1',
                    'loginPath'    => LoginUri::LOGIN->value,
                    'userName'     => $this->username,
                    'password'     => $this->password,
                ],
            )
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
