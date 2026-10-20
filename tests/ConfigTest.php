<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests;

use Brightspace\Api\Core\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @param int|string|null $mfa
     * @return Config
     */
    private static function configWithMfa(int|string|null $mfa): Config
    {
        return new Config(
            d2lHost: 'lms.example.edu',
            rootOrgUnit: 6606,
            clientId: 'client-id',
            clientSecret: 'client-secret',
            redirectUri: 'https://app.example.edu/callback',
            scope: 'core:*:*',
            username: 'svc-user',
            password: 'secret',
            mfa: $mfa
        );
    }


    public function testKeepsEveryValueItIsGiven(): void
    {
        $config = new Config(
            d2lHost: 'lms.example.edu',
            rootOrgUnit: 6606,
            clientId: 'client-id',
            clientSecret: 'client-secret',
            redirectUri: 'https://app.example.edu/callback',
            scope: 'core:*:*',
            username: 'svc-user',
            password: 'secret',
            mfa: 'JBSWY3DPEHPK3PXP',
            authUri: 'https://auth.example.edu/auth',
            tokenUri: 'https://auth.example.edu/token'
        );

        self::assertSame('lms.example.edu', $config->d2lHost);
        self::assertSame(6606, $config->rootOrgUnit);
        self::assertSame('client-id', $config->clientId);
        self::assertSame('client-secret', $config->clientSecret);
        self::assertSame('https://app.example.edu/callback', $config->redirectUri);
        self::assertSame('core:*:*', $config->scope);
        self::assertSame('svc-user', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame('JBSWY3DPEHPK3PXP', $config->mfa);
        self::assertSame('https://auth.example.edu/auth', $config->authUri);
        self::assertSame('https://auth.example.edu/token', $config->tokenUri);
    }


    public function testDefaultsToTheBrightspaceAuthEndpoints(): void
    {
        $config = self::configWithMfa(null);

        self::assertSame('https://auth.brightspace.com/oauth2/auth', $config->authUri);
        self::assertSame('https://auth.brightspace.com/core/connect/token', $config->tokenUri);
        self::assertNull($config->mfa);
    }


    /** @return iterable<string,array{int|string|null,int|string|null}> */
    public static function mfaValues(): iterable
    {
        yield 'zero means no MFA' => [0, null];
        yield 'empty string means no MFA' => ['', null];
        yield 'a TOTP key is kept' => ['JBSWY3DPEHPK3PXP', 'JBSWY3DPEHPK3PXP'];
        yield 'a fixed code is kept' => [123456, 123456];
        yield 'null stays null' => [null, null];
    }


    /**
     * @dataProvider mfaValues
     * @param int|string|null $given
     * @param int|string|null $expected
     */
    public function testTreatsAnEmptyMfaSettingAsNone(int|string|null $given, int|string|null $expected): void
    {
        self::assertSame($expected, self::configWithMfa($given)->mfa);
    }
}
