<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Model;

use Brightspace\Api\Core\Model\LoginUri;
use PHPUnit\Framework\TestCase;

final class LoginUriTest extends TestCase
{
    public function testMapsEachCaseToItsBrightspacePath(): void
    {
        self::assertSame('/d2l/lp/preferences/authenticatorLoginAction/entercoderedirect', LoginUri::PRE_MFA->value);
        self::assertSame('/d2l/lp/auth/login/login.d2l', LoginUri::LOGIN->value);
        self::assertSame('/d2l/lp/auth/twofactorauthentication/TwoFactorCodeEntry.d2l', LoginUri::MFA->value);
        self::assertSame('/d2l/lp/auth/login/ProcessLoginActions.d2l', LoginUri::PROCESS_LOGIN->value);
        self::assertSame('/d2l/home', LoginUri::HOME->value);
    }


    /** @return iterable<string,array{string,LoginUri|null}> */
    public static function redirectLocations(): iterable
    {
        yield 'an exact path' => ['/d2l/home', LoginUri::HOME];
        yield 'a path with a query string' => ['/d2l/home?ou=6606', null];
        yield 'an absolute URL' => ['https://lms.example.edu/d2l/home', null];
        yield 'nothing' => ['', null];
    }


    /**
     * @dataProvider redirectLocations
     * @param string $location
     * @param LoginUri|null $expected
     */
    public function testRecognizesARedirectLocationOnlyByItsExactPath(string $location, LoginUri|null $expected): void
    {
        self::assertSame($expected, LoginUri::tryFrom($location));
    }
}
