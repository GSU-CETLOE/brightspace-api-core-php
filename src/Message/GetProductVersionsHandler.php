<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Message;

use Brightspace\Api\Core\Model\ProductVersion;
use Gadget\Http\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Gadget\Io\Cast;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @extends MessageHandler<array<string,ProductVersion>> */
class GetProductVersionsHandler extends MessageHandler
{
    /**
     * @param string $d2lHost
     */
    public function __construct(private string $d2lHost)
    {
    }


    /**
     * @param RequestBuilder $requestBuilder
     * @return ServerRequestInterface
     */
    protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
    {
        return $requestBuilder
            ->setMethod('GET')
            ->setUri('https://' . $this->d2lHost . '/d2l/api/versions/')
            ->getRequest();
    }


    /**
     * @param ResponseInterface $response
     * @param ServerRequestInterface $request
     * @return array<string,ProductVersion>
     */
    protected function handleResponse(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): mixed {
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Status code: " . $response->getStatusCode());
        }

        return Cast::toTypedMap(
            $this->jsonToArray($response),
            $this->createProductVersion(...),
            fn(ProductVersion $pv): string => $pv->ProductCode
        );
    }


    /**
     * @param mixed $v
     * @return ProductVersion
     */
    private function createProductVersion(mixed $v): ProductVersion
    {
        $v = Cast::toArray($v);
        return new ProductVersion(
            ProductCode: Cast::toString($v['ProductCode'] ?? null),
            LatestVersion: Cast::toString($v['LatestVersion'] ?? null)
        );
    }
}
