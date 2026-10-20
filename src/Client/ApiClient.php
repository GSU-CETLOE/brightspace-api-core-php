<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Client;

use Brightspace\Api\Core\Client\CoreClient;
use Brightspace\Api\Core\Config;
use Brightspace\Api\Core\Message\MessageHandler;
use Gadget\Http\Client\ApiClient as BaseApiClient;
use Gadget\Http\Client\Client;
use Gadget\Http\Message\MessageHandler as BaseMessageHandler;

class ApiClient extends BaseApiClient
{
    /**
     * @param Client $client
     * @param Config $config
     * @param CoreClient $coreClient
     */
    public function __construct(
        Client $client,
        private Config $config,
        private CoreClient $coreClient
    ) {
        parent::__construct($client);
    }


    /**
     * @return Config
     */
    protected function getConfig(): Config
    {
        return $this->config;
    }


    /**
     * @return Config
     */
    protected function getCoreClient(): Config
    {
        return $this->config;
    }


    /**
     * @template TResponse
     * @param BaseMessageHandler<TResponse> $handler
     * @return TResponse
     */
    protected function invoke(BaseMessageHandler $handler): mixed
    {
        if ($handler instanceof MessageHandler) {
            $handler->init(
                $this->config,
                $this->coreClient
            );
        }

        return parent::invoke($handler);
    }
}
