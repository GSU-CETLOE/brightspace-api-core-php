<?php

declare(strict_types=1);

namespace Brightspace\Api\Core;

class Config
{
    public function __construct(
        public string $d2lHost,
        public int $rootOrgUnit,
        public string $clientId,
        public string $clientSecret,
        public string $redirectUri,
        public string $scope,
        public string $username,
        public string $password,
        public int|string|null $mfa = null,
        public string $authUri = 'https://auth.brightspace.com/oauth2/auth',
        public string $tokenUri = 'https://auth.brightspace.com/core/connect/token'
    ) {
        if ($mfa === 0 || $mfa === '') {
            $this->mfa = $mfa = null;
        }
    }
}
