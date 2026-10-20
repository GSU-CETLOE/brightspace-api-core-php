<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Client;

use Brightspace\Api\Core\Message\GenerateMFAHandler;
use Brightspace\Api\Core\Message\GetProductVersionsHandler;
use Brightspace\Api\Core\Message\ProcessLoginActionsHandler;
use Brightspace\Api\Core\Message\SubmitCredentialsHandler;
use Brightspace\Api\Core\Message\SubmitMFAHandler;
use Brightspace\Api\Core\Model\LoginUri;
use Brightspace\Api\Core\Model\ProductVersion;
use Brightspace\Api\Core\Config;
use Gadget\Http\Client\Client;
use Gadget\Oauth\Client\AuthClient;
use Gadget\Oauth\Model\AuthCode;
use Gadget\Oauth\Model\PKCE;

class CoreApiClient extends AuthClient
{
    /**
     * @param Client $client
     * @param Config $config
     */
    public function __construct(
        Client $client,
        private Config $config
    ) {
        parent::__construct(
            $client,
            $config->authUri,
            $config->tokenUri,
            $config->clientId,
            $config->clientSecret,
            $config->scope,
            $config->redirectUri
        );
    }


    /**
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        $cookieJar = $this->getClient()->getCookieJar();
        $d2lSecureSessionVal = $cookieJar->getCookie(
            $this->config->d2lHost,
            '/',
            'd2lSecureSessionVal'
        );
        $d2lSessionVal = $cookieJar->getCookie(
            $this->config->d2lHost,
            '/',
            'd2lSessionVal'
        );

        return (
            ($d2lSecureSessionVal?->isExpired() ?? true) === false
            && ($d2lSessionVal?->isExpired() ?? true) === false
        );
    }


    /**
     * @param string $username
     * @param string $password
     * @param string|int|null $mfa
     * @param int|null $rootOrgUnit
     * @param string|null $d2lHost
     * @return bool
     */
    public function login(
        string|null $username = null,
        string|null $password = null,
        string|int|null $mfa = null,
        int|null $rootOrgUnit = null,
        string|null $d2lHost = null,
    ): bool {
        if ($username === null || $password === null) {
            $username ??= $this->config->username;
            $password ??= $this->config->password;
            $mfa ??= $this->config->mfa;
        }
        $rootOrgUnit ??= $this->config->rootOrgUnit;
        $d2lHost ??= $this->config->d2lHost;

        $next = $this->invoke(new SubmitCredentialsHandler(
            $d2lHost,
            $username,
            $password,
        )) ?? throw new \RuntimeException();

        if ($next === LoginUri::MFA || $next === LoginUri::PRE_MFA) {
            if ($mfa === null) {
                throw new \RuntimeException();
            }

            $mfaBody = $this->invoke(new GenerateMFAHandler($d2lHost, $mfa));

            $next = $this->invoke(new SubmitMFAHandler($d2lHost, $rootOrgUnit, $mfaBody))
                ?? throw new \RuntimeException();
        }

        if ($next === LoginUri::PROCESS_LOGIN) {
            $next = $this->invoke(new ProcessLoginActionsHandler($d2lHost))
                ?? throw new \RuntimeException();
        }

        return $next === LoginUri::HOME;
    }


    /**
     * @param string|null $state
     * @param PKCE|null $pkce
     * @param string|null $authUri
     * @param string|null $clientId
     * @param string|null $redirectUri
     * @param string|null $scope
     * @return AuthCode
     */
    public function createAuthCode(
        string|null $state = null,
        PKCE|null $pkce = null,
        string|null $authUri = null,
        string|null $clientId = null,
        string|null $redirectUri = null,
        string|null $scope = null
    ): AuthCode {
        if (!$this->isLoggedIn()) {
            throw new \RuntimeException();
        }

        return parent::createAuthCode(
            $state,
            $pkce,
            $authUri,
            $clientId,
            $redirectUri,
            $scope
        );
    }


    /**
     * @param string|null $d2lHost
     * @return array<string,ProductVersion>
     */
    public function getProductVersions(string|null $d2lHost = null): array
    {
        $d2lHost ??= $this->config->d2lHost ?? throw new \RuntimeException();
        return $this->invoke(new GetProductVersionsHandler($d2lHost));
    }
}
