<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Message;

use Brightspace\Api\Core\Client\CoreClient;
use Brightspace\Api\Core\Config;
use Gadget\Http\Client\Client;
use Gadget\Http\Message\MessageHandler as BaseMessageHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @template TResponse
 * @extends BaseMessageHandler<TResponse>
 */
abstract class MessageHandler extends BaseMessageHandler
{
    private Config|null $config = null;
    private CoreClient|null $coreClient = null;
    private bool $useAccessToken = true;
    private bool $useWebLogin = false;


    /**
     * @return Config
     */
    protected function getConfig(): Config
    {
        return $this->config ?? throw new \RuntimeException();
    }


    /**
     * @return CoreClient
     */
    protected function getCoreClient(): CoreClient
    {
        return $this->coreClient ?? throw new \RuntimeException();
    }


    /**
     * @param bool $useAccessToken
     * @return static
     */
    public function useAccessToken(bool $useAccessToken): static
    {
        $this->useAccessToken = $useAccessToken;
        return $this;
    }


    /**
     * @param bool $useWebLogin
     * @return static
     */
    public function useWebLogin(bool $useWebLogin): static
    {
        $this->useWebLogin = $useWebLogin;
        return $this;
    }


    /**
     * @param Config $config
     * @param CoreClient $coreClient
     * @return static
     */
    public function init(
        Config $config,
        CoreClient $coreClient
    ): static {
        $this->config = $config;
        $this->coreClient = $coreClient;
        return $this;
    }


    /**
     * @param Client $client
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function sendRequest(
        Client $client,
        ServerRequestInterface $request
    ): ResponseInterface {
        $request = $this->withD2lPath($request);

        if ($this->useAccessToken) {
            $request = $request->withHeader(
                'Authorization',
                'Bearer ' . $this->getCoreClient()->getAccessToken()
            );
        }

        if ($this->useWebLogin) {
            $this->getCoreClient()->login();
        }

        return parent::sendRequest($client, $request);
    }


    /**
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    protected function withD2lPath(ServerRequestInterface $request): ServerRequestInterface
    {
        $uri = $request->getUri();
        if ($uri->getScheme() !== 'd2l') {
            return $request;
        }

        $productCode = $uri->getHost();
        $path = $uri->getPath();

        if (!in_array($productCode, ['api', 'web'], true) && !str_starts_with($path, '/d2l/api/')) {
            $path = sprintf(
                "/d2l/api/%s/%s%s",
                $productCode,
                $this->getCoreClient()->getProductVersion($productCode),
                $path
            );
        }

        return $request->withUri(
            $uri
                ->withScheme('https')
                ->withHost($this->getConfig()->d2lHost)
                ->withPath($path)
        );
    }
}
