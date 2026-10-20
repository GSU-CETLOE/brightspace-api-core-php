<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Client;

use Brightspace\Api\Core\Client\CoreApiClient;
use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use Gadget\Http\Client\Client;
use GuzzleHttp\Psr7\Response;

final class CoreApiClientTest extends BrightspaceTestCase
{
    private const LOGIN_PATH = '/d2l/lp/auth/login/login.d2l';
    private const MFA_PATH = '/d2l/lp/auth/twofactorauthentication/TwoFactorCodeEntry.d2l';
    private const PROCESS_LOGIN_PATH = '/d2l/lp/auth/login/ProcessLoginActions.d2l';


    private function mfaPage(): Response
    {
        return new Response(
            200,
            [],
            'D2L.LP.Web.Authentication.Xsrf.Init("{\"P\":[\"X-Csrf-Token\",\"abc123\",\"7\"]}");'
        );
    }


    /**
     * @return string[] the paths of every request that reached the transport, in order
     */
    private function sentPaths(): array
    {
        return array_map(fn($request): string => $request->getUri()->getPath(), $this->sentRequests);
    }


    public function testIsNotLoggedInWithAnEmptyCookieJar(): void
    {
        self::assertFalse((new CoreApiClient($this->createClient(), self::createConfig()))->isLoggedIn());
    }


    public function testIsLoggedInWhenBothSessionCookiesAreCurrent(): void
    {
        $client = $this->createClient();
        self::markLoggedIn($client);

        self::assertTrue((new CoreApiClient($client, self::createConfig()))->isLoggedIn());
    }


    public function testIsNotLoggedInWhenTheSessionCookiesHaveExpired(): void
    {
        $client = $this->createClient();
        self::markLoggedIn($client, time() - 60);

        self::assertFalse((new CoreApiClient($client, self::createConfig()))->isLoggedIn());
    }


    public function testIsNotLoggedInWithOnlyOneOfTheSessionCookies(): void
    {
        $client = $this->createClient();
        $client->getCookieJar()->setCookie([
            'Name' => 'd2lSessionVal',
            'Value' => 'x',
            'Domain' => self::D2L_HOST,
            'Path' => '/',
        ]);

        self::assertFalse((new CoreApiClient($client, self::createConfig()))->isLoggedIn());
    }


    public function testIsNotLoggedInWhenTheCookiesBelongToAnotherHost(): void
    {
        $client = $this->createClient();
        self::markLoggedIn($client);

        $api = new CoreApiClient($client, self::createConfig(d2lHost: 'other.example.edu'));

        self::assertFalse($api->isLoggedIn());
    }


    public function testLoginSubmitsTheConfiguredCredentials(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home', self::sessionCookieHeaders()));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        self::assertTrue($api->login());

        self::assertSame([self::LOGIN_PATH], $this->sentPaths());
        $form = self::formBodyOf($this->sentRequest());
        self::assertSame('svc-user', $form['userName']);
        self::assertSame('secret', $form['password']);
        self::assertTrue($api->isLoggedIn());
    }


    public function testLoginUsesTheGivenCredentialsAndHost(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home'));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        $api->login('someone-else', 'other-secret', d2lHost: 'other.example.edu');

        self::assertSame('other.example.edu', $this->sentRequest()->getUri()->getHost());
        $form = self::formBodyOf($this->sentRequest());
        self::assertSame('someone-else', $form['userName']);
        self::assertSame('other-secret', $form['password']);
    }


    public function testLoginFollowsProcessLoginActionsToHome(): void
    {
        $this->transport->append(self::redirectTo(self::PROCESS_LOGIN_PATH));
        $this->transport->append(self::redirectTo('/d2l/home'));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        self::assertTrue($api->login());

        self::assertSame([self::LOGIN_PATH, self::PROCESS_LOGIN_PATH], $this->sentPaths());
    }


    public function testLoginCompletesMultiFactorAuthenticationWithTheConfiguredCode(): void
    {
        $this->transport->append(self::redirectTo(self::MFA_PATH));
        $this->transport->append($this->mfaPage());
        $this->transport->append(new Response(200, [], '{}'));
        $this->transport->append(self::redirectTo('/d2l/home'));
        $api = new CoreApiClient($this->createClient(), self::createConfig(mfa: 123456));

        self::assertTrue($api->login());

        self::assertSame(
            [self::LOGIN_PATH, self::MFA_PATH, self::MFA_PATH, self::PROCESS_LOGIN_PATH],
            $this->sentPaths()
        );
        self::assertSame('GET', $this->sentRequest(1)->getMethod());
        self::assertSame('POST', $this->sentRequest(2)->getMethod());
        self::assertSame(['ou' => '6606', 'd2l_rh' => 'rpc', 'd2l_rt' => 'call'], self::queryOf($this->sentRequest(2)));
        $form = self::formBodyOf($this->sentRequest(2));
        self::assertSame('{"param1":"123456"}', $form['params']);
        self::assertSame('abc123', $form['X-Csrf-Token']);
    }


    public function testLoginTreatsThePreMfaRedirectLikeTheMfaPage(): void
    {
        $this->transport->append(self::redirectTo('/d2l/lp/preferences/authenticatorLoginAction/entercoderedirect'));
        $this->transport->append($this->mfaPage());
        $this->transport->append(new Response(200, [], '{}'));
        $this->transport->append(self::redirectTo('/d2l/home'));
        $api = new CoreApiClient($this->createClient(), self::createConfig(mfa: 123456));

        self::assertTrue($api->login());

        self::assertSame(
            [self::LOGIN_PATH, self::MFA_PATH, self::MFA_PATH, self::PROCESS_LOGIN_PATH],
            $this->sentPaths()
        );
    }


    public function testLoginFailsWhenMfaIsRequiredButNotConfigured(): void
    {
        $this->transport->append(self::redirectTo(self::MFA_PATH));
        $api = new CoreApiClient($this->createClient(), self::createConfig(mfa: null));

        $this->expectException(\RuntimeException::class);

        try {
            $api->login();
        } finally {
            self::assertSame([self::LOGIN_PATH], $this->sentPaths());
        }
    }


    public function testExplicitCredentialsDoNotPickUpTheConfiguredMfaCode(): void
    {
        $this->transport->append(self::redirectTo(self::MFA_PATH));
        $api = new CoreApiClient($this->createClient(), self::createConfig(mfa: 123456));

        $this->expectException(\RuntimeException::class);

        $api->login('someone-else', 'other-secret');
    }


    public function testLoginFailsWhenMfaIsRejected(): void
    {
        $this->transport->append(self::redirectTo(self::MFA_PATH));
        $this->transport->append($this->mfaPage());
        $this->transport->append(new Response(403, [], 'bad code'));
        $api = new CoreApiClient($this->createClient(), self::createConfig(mfa: 123456));

        $this->expectException(\RuntimeException::class);

        $api->login();
    }


    public function testLoginFailsWhenTheCredentialsAreRejected(): void
    {
        $this->transport->append(new Response(200, [], '<html>try again</html>'));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        $this->expectException(\RuntimeException::class);

        $api->login();
    }


    public function testLoginFailsWhenProcessLoginActionsLeadsNowhere(): void
    {
        $this->transport->append(self::redirectTo(self::PROCESS_LOGIN_PATH));
        $this->transport->append(new Response(200, [], 'stuck'));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        $this->expectException(\RuntimeException::class);

        $api->login();
    }


    public function testLoginIsFalseWhenBrightspaceSendsTheUserBackToTheLoginPage(): void
    {
        $this->transport->append(self::redirectTo(self::LOGIN_PATH));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        self::assertFalse($api->login());
    }


    public function testCreateAuthCodeRequiresAWebSession(): void
    {
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        $this->expectException(\RuntimeException::class);

        try {
            $api->createAuthCode();
        } finally {
            self::assertSame([], $this->sentRequests, 'Nothing should reach the transport');
        }
    }


    public function testCreateAuthCodeAsksTheConfiguredAuthEndpointWithTheConfiguredClient(): void
    {
        $this->transport->append(self::authCodeRedirect('the-auth-code'));
        $client = $this->createClient();
        self::markLoggedIn($client);
        $api = new CoreApiClient($client, self::createConfig());

        $authCode = $api->createAuthCode(state: 'xyz');

        self::assertSame('the-auth-code', $authCode->code);
        $sent = $this->sentRequest();
        self::assertSame('https://auth.brightspace.com/oauth2/auth', (string) $sent->getUri()->withQuery(''));
        self::assertSame([
            'response_type' => 'code',
            'client_id' => 'client-id',
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'core:*:*',
            'state' => 'xyz',
        ], self::queryOf($sent));
    }


    public function testCreateTokenPostsToTheConfiguredTokenEndpoint(): void
    {
        $this->transport->append(self::tokenResponse('fresh-access-token'));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        $token = $api->createToken('the-auth-code');

        self::assertSame('fresh-access-token', $token->accessToken);
        $sent = $this->sentRequest();
        self::assertSame('https://auth.brightspace.com/core/connect/token', (string) $sent->getUri());
        $form = self::formBodyOf($sent);
        self::assertSame('authorization_code', $form['grant_type']);
        self::assertSame('client-id', $form['client_id']);
        self::assertSame('client-secret', $form['client_secret']);
        self::assertSame('the-auth-code', $form['code']);
        self::assertSame(self::REDIRECT_URI, $form['redirect_uri']);
    }


    public function testGetProductVersionsUsesTheConfiguredHost(): void
    {
        $this->transport->append(self::productVersionsResponse(['lp' => '1.46']));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        $versions = $api->getProductVersions();

        self::assertSame('https://lms.example.edu/d2l/api/versions/', (string) $this->sentRequest()->getUri());
        self::assertSame('1.46', $versions['lp']->LatestVersion);
    }


    public function testGetProductVersionsCanAskAnotherHost(): void
    {
        $this->transport->append(self::productVersionsResponse([]));
        $api = new CoreApiClient($this->createClient(), self::createConfig());

        $api->getProductVersions('other.example.edu');

        self::assertSame('https://other.example.edu/d2l/api/versions/', (string) $this->sentRequest()->getUri());
    }


    public function testSharesTheCookieJarWithTheClientItWraps(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home', self::sessionCookieHeaders()));
        $client = $this->createClient();
        $api = new CoreApiClient($client, self::createConfig());

        $api->login();

        self::assertInstanceOf(Client::class, $client);
        self::assertNotNull($client->getCookieJar()->getCookie(self::D2L_HOST, '/', 'd2lSecureSessionVal'));
    }
}
