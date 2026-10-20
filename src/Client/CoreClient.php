<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Client;

use Brightspace\Api\Core\Model\ProductVersion;
use Gadget\Http\Client\ApiClient;
use Gadget\Http\Client\Client;
use Gadget\Oauth\Model\AuthCode;
use Gadget\Oauth\Model\Token;

class CoreClient extends ApiClient
{
    /**
     * @param Client $client
     * @param CoreApiClient $coreApiClient
     */
    public function __construct(
        Client $client,
        private CoreApiClient $coreApiClient
    ) {
        parent::__construct($client);
    }


    /**
     * @param string $productCode
     * @return string
     */
    public function getProductVersion(string $productCode): string
    {
        $cache = $this->getClient()->getCache();

        /** @var array<string,ProductVersion>|null $versions */
        $versions = $cache->get('productVersions');
        if ($versions === null) {
            $versions = $this->coreApiClient->getProductVersions();
            $cache->set('productVersions', $versions, time() + 3600);
        }

        return ($versions[$productCode] ?? null)->LatestVersion ?? '1.0';
    }


    /**
     * @return string
     */
    public function getAccessToken(): string
    {
        $cache = $this->getClient()->getCache();

        $token = $cache->getObject('token', Token::class)
            ?? $this->createToken();

        if ($token->expiresIn < time()) {
            $token = $this->refreshToken($token->refreshToken ?? throw new \RuntimeException());
        }

        if ($token->accessToken === null) {
            throw new \RuntimeException();
        }

        $cache->set('token', $token);
        return $token->accessToken;
    }


    /**
     * @return bool
     */
    public function login(): bool
    {
        return $this->coreApiClient->isLoggedIn() || $this->coreApiClient->login();
    }


    /**
     * @param AuthCode|null $authCode
     * @return Token
     */
    public function createToken(AuthCode|null $authCode = null): Token
    {
        if ($authCode === null) {
            $this->login();
            $authCode = $this->coreApiClient->createAuthCode();
        }

        return $this->coreApiClient->createToken($authCode->code);
    }


    /**
     * @param string $refreshToken
     * @return Token
     */
    public function refreshToken(string $refreshToken): Token
    {
        return $this->coreApiClient->refreshToken($refreshToken);
    }
}
