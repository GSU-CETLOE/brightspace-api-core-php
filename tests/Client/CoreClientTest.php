<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Client;

use Brightspace\Api\Core\Model\ProductVersion;
use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use Gadget\Oauth\Model\AuthCode;
use Gadget\Oauth\Model\Token;

final class CoreClientTest extends BrightspaceTestCase
{
    /**
     * @return string[] the paths of every request that reached the transport, in order
     */
    private function sentPaths(): array
    {
        return array_map(fn($request): string => $request->getUri()->getPath(), $this->sentRequests);
    }


    public function testGetProductVersionLooksUpTheTableOnceAndCachesIt(): void
    {
        $this->transport->append(self::productVersionsResponse(['lp' => '1.46', 'le' => '1.79']));
        $core = $this->createCoreClient($this->createClient());

        self::assertSame('1.46', $core->getProductVersion('lp'));
        self::assertSame('1.79', $core->getProductVersion('le'));

        self::assertCount(1, $this->sentRequests);
        $cached = $this->clientCache()->get('productVersions');
        self::assertIsArray($cached);
        self::assertInstanceOf(ProductVersion::class, $cached['lp']);
    }


    public function testGetProductVersionUsesACachedTableWithoutARequest(): void
    {
        $this->clientCache()->set('productVersions', ['lp' => new ProductVersion('lp', '1.46')]);
        $core = $this->createCoreClient($this->createClient());

        self::assertSame('1.46', $core->getProductVersion('lp'));
        self::assertSame([], $this->sentRequests);
    }


    public function testAnUnknownProductFallsBackToVersionOne(): void
    {
        $this->clientCache()->set('productVersions', ['lp' => new ProductVersion('lp', '1.46')]);
        $core = $this->createCoreClient($this->createClient());

        self::assertSame('1.0', $core->getProductVersion('nope'));
    }


    public function testGetAccessTokenReturnsACachedTokenThatIsStillValid(): void
    {
        $this->clientCache()->set('token', self::token('cached-access-token'));
        $core = $this->createCoreClient($this->createClient());

        self::assertSame('cached-access-token', $core->getAccessToken());
        self::assertSame([], $this->sentRequests, 'A valid cached token needs no request');
    }


    public function testGetAccessTokenRefreshesACachedTokenThatHasExpired(): void
    {
        $this->clientCache()->set(
            'token',
            self::token('stale-access-token', 'stale-refresh-token', createdOn: time() - 7200, expiresIn: 3600)
        );
        $this->transport->append(self::tokenResponse('fresh-access-token', 'fresh-refresh-token'));
        $core = $this->createCoreClient($this->createClient());

        self::assertSame('fresh-access-token', $core->getAccessToken());

        self::assertCount(1, $this->sentRequests);
        $form = self::formBodyOf($this->sentRequest());
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('stale-refresh-token', $form['refresh_token']);
        $cached = $this->clientCache()->getObject('token', Token::class);
        self::assertNotNull($cached);
        self::assertSame('fresh-refresh-token', $cached->refreshToken);
    }


    public function testGetAccessTokenFailsWhenAnExpiredTokenCannotBeRefreshed(): void
    {
        $this->clientCache()->set(
            'token',
            self::token('stale-access-token', null, createdOn: time() - 7200, expiresIn: 3600)
        );
        $core = $this->createCoreClient($this->createClient());

        $this->expectException(\RuntimeException::class);

        $core->getAccessToken();
    }


    public function testGetAccessTokenFailsWhenTheTokenHasNoAccessToken(): void
    {
        $this->clientCache()->set('token', self::token(null));
        $core = $this->createCoreClient($this->createClient());

        $this->expectException(\RuntimeException::class);

        $core->getAccessToken();
    }


    public function testGetAccessTokenLogsInAndCreatesATokenWhenNoneIsCached(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home', self::sessionCookieHeaders()));
        $this->transport->append(self::authCodeRedirect('the-auth-code'));
        $this->transport->append(self::tokenResponse('new-access-token'));
        $core = $this->createCoreClient($this->createClient());

        self::assertSame('new-access-token', $core->getAccessToken());

        self::assertSame(['/d2l/lp/auth/login/login.d2l', '/oauth2/auth', '/core/connect/token'], $this->sentPaths());
        self::assertSame('the-auth-code', self::formBodyOf($this->sentRequest(2))['code']);
        $cached = $this->clientCache()->getObject('token', Token::class);
        self::assertNotNull($cached);
        self::assertSame('new-access-token', $cached->accessToken);
    }


    public function testLoginIsSkippedWhenTheWebSessionIsStillValid(): void
    {
        $client = $this->createClient();
        self::markLoggedIn($client);
        $core = $this->createCoreClient($client);

        self::assertTrue($core->login());
        self::assertSame([], $this->sentRequests);
    }


    public function testLoginRunsTheWebLoginWhenThereIsNoSession(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home', self::sessionCookieHeaders()));
        $core = $this->createCoreClient($this->createClient());

        self::assertTrue($core->login());
        self::assertSame(['/d2l/lp/auth/login/login.d2l'], $this->sentPaths());
    }


    public function testCreateTokenExchangesTheGivenAuthCode(): void
    {
        $this->transport->append(self::tokenResponse('new-access-token'));
        $core = $this->createCoreClient($this->createClient());
        $authCode = new AuthCode(self::REDIRECT_URI, 'xyz', 'code', 'given-code', null, null);

        $token = $core->createToken($authCode);

        self::assertSame('new-access-token', $token->accessToken);
        self::assertSame(['/core/connect/token'], $this->sentPaths());
        self::assertSame('given-code', self::formBodyOf($this->sentRequest())['code']);
    }


    public function testCreateTokenWithoutAnAuthCodeLogsInAndGetsOne(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home', self::sessionCookieHeaders()));
        $this->transport->append(self::authCodeRedirect('the-auth-code'));
        $this->transport->append(self::tokenResponse('new-access-token'));
        $core = $this->createCoreClient($this->createClient());

        $token = $core->createToken();

        self::assertSame('new-access-token', $token->accessToken);
        self::assertSame(['/d2l/lp/auth/login/login.d2l', '/oauth2/auth', '/core/connect/token'], $this->sentPaths());
    }


    public function testRefreshTokenPostsTheRefreshGrant(): void
    {
        $this->transport->append(self::tokenResponse('fresh-access-token'));
        $core = $this->createCoreClient($this->createClient());

        $token = $core->refreshToken('old-refresh-token');

        self::assertSame('fresh-access-token', $token->accessToken);
        $form = self::formBodyOf($this->sentRequest());
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('old-refresh-token', $form['refresh_token']);
    }
}
