<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Support;

use Brightspace\Api\Core\Client\ApiClient;
use Brightspace\Api\Core\Client\CoreClient;
use Brightspace\Api\Core\Config;
use Gadget\Http\Message\MessageHandler;

/**
 * Makes the protected ApiClient methods callable from a test.
 */
final class ExposedApiClient extends ApiClient
{
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
     * @template TResponse
     * @param MessageHandler<TResponse> $handler
     * @return TResponse
     */
    public function exposeInvoke(MessageHandler $handler): mixed
    {
        return $this->invoke($handler);
    }
}
