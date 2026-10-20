<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Message;

use Brightspace\Api\Core\Model\LoginUri;
use Brightspace\Api\Core\Model\MfaBody;
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @extends MessageHandler<LoginUri|null> */
class SubmitMFAHandler extends MessageHandler
{
    /**
     * @param string $d2lHost
     * @param int $rootOrgUnit
     * @param MfaBody $mfaBody
     */
    public function __construct(
        private string $d2lHost,
        private int $rootOrgUnit,
        private MfaBody $mfaBody
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
            ->setUri('https://' . $this->d2lHost . LoginUri::MFA->value)
            ->setQueryParams([
                'ou' => $this->rootOrgUnit,
                'd2l_rh' => 'rpc',
                'd2l_rt' => 'call'
            ])
            ->setBody(
                'application/x-www-form-urlencoded',
                $this->mfaBody->getBody()
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
        return ($response->getStatusCode() === 200)
            ? LoginUri::PROCESS_LOGIN
            : null;
    }
}
