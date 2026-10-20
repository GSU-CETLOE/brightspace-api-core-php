<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Message;

use Brightspace\Api\Core\Message\SubmitCredentialsHandler;
use Brightspace\Api\Core\Model\LoginUri;
use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use GuzzleHttp\Psr7\Response;

final class SubmitCredentialsHandlerTest extends BrightspaceTestCase
{
    public function testPostsTheCredentialsAsTheLoginForm(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home'));

        (new SubmitCredentialsHandler(self::D2L_HOST, 'svc-user', 'p@ss word'))->invoke($this->createClient());

        $sent = $this->sentRequest();
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://lms.example.edu/d2l/lp/auth/login/login.d2l', (string) $sent->getUri());
        self::assertSame('application/x-www-form-urlencoded', $sent->getHeaderLine('Content-Type'));
        self::assertSame([
            'd2l_referrer' => '',
            'noredirect' => '1',
            'loginPath' => '/d2l/lp/auth/login/login.d2l',
            'userName' => 'svc-user',
            'password' => 'p@ss word',
        ], self::formBodyOf($sent));
    }


    /** @return iterable<string,array{string,LoginUri|null}> */
    public static function redirectLocations(): iterable
    {
        yield 'home' => ['/d2l/home', LoginUri::HOME];
        yield 'process login actions' => ['/d2l/lp/auth/login/ProcessLoginActions.d2l', LoginUri::PROCESS_LOGIN];
        yield 'MFA code entry' => ['/d2l/lp/auth/twofactorauthentication/TwoFactorCodeEntry.d2l', LoginUri::MFA];
        yield 'pre MFA' => ['/d2l/lp/preferences/authenticatorLoginAction/entercoderedirect', LoginUri::PRE_MFA];
        yield 'back to the login page' => ['/d2l/lp/auth/login/login.d2l', LoginUri::LOGIN];
        yield 'somewhere unknown' => ['/d2l/lp/auth/login/somethingElse.d2l', null];
    }


    /**
     * @dataProvider redirectLocations
     * @param string $location
     * @param LoginUri|null $expected
     */
    public function testReadsTheNextStepFromTheRedirectLocation(string $location, LoginUri|null $expected): void
    {
        $this->transport->append(self::redirectTo($location));

        $next = (new SubmitCredentialsHandler(self::D2L_HOST, 'svc-user', 'secret'))->invoke($this->createClient());

        self::assertSame($expected, $next);
    }


    public function testAnythingButARedirectMeansNoNextStep(): void
    {
        $this->transport->append(new Response(200, [], '<html>login form again</html>'));

        $next = (new SubmitCredentialsHandler(self::D2L_HOST, 'svc-user', 'secret'))->invoke($this->createClient());

        self::assertNull($next);
    }


    public function testARedirectWithoutALocationMeansNoNextStep(): void
    {
        $this->transport->append(new Response(302));

        $next = (new SubmitCredentialsHandler(self::D2L_HOST, 'svc-user', 'secret'))->invoke($this->createClient());

        self::assertNull($next);
    }
}
