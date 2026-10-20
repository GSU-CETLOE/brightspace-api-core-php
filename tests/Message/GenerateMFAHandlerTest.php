<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Message;

use Brightspace\Api\Core\Message\GenerateMFAHandler;
use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use Gadget\Http\Exception\ResponseException;
use Gadget\Util\MFA\TOTP;
use GuzzleHttp\Psr7\Response;

final class GenerateMFAHandlerTest extends BrightspaceTestCase
{
    private const TOTP_KEY = 'JBSWY3DPEHPK3PXP';


    /**
     * A cut-down MFA page. The line that matters is the one Brightspace uses to initialize its
     * XSRF handling: a JSON string, escaped for JavaScript, whose "P" array holds the XSRF form
     * field name, the XSRF code, and the server's hit code.
     *
     * @param string $xsrfLine
     * @return Response
     */
    private static function mfaPage(
        string $xsrfLine = 'D2L.LP.Web.Authentication.Xsrf.Init("{\"P\":[\"X-Csrf-Token\",\"abc123\",\"7\"]}");'
    ): Response {
        return new Response(200, ['Content-Type' => 'text/html'], implode("\n", [
            '<html><head><title>Enter code</title>',
            '<script>',
            'D2L.LP.Web.UI.Rpc.Init("{\"P\":[\"ignored\"]}");',
            $xsrfLine,
            '</script></head><body></body></html>',
        ]));
    }


    /**
     * The hit code is the server's number plus the current time in milliseconds, wrapped at 10^8.
     *
     * @param int $serverHitCode
     * @param int $time
     * @return int
     */
    private static function expectedHitCode(int $serverHitCode, int $time): int
    {
        return $serverHitCode + ((1000 * $time + 100000000) % 100000000);
    }


    public function testGetsTheMfaPage(): void
    {
        $this->transport->append(self::mfaPage());

        (new GenerateMFAHandler(self::D2L_HOST, 123456))->invoke($this->createClient());

        $sent = $this->sentRequest();
        self::assertSame('GET', $sent->getMethod());
        self::assertSame(
            'https://lms.example.edu/d2l/lp/auth/twofactorauthentication/TwoFactorCodeEntry.d2l',
            (string) $sent->getUri()
        );
    }


    public function testReadsTheXsrfFieldsAndUsesAFixedCodeAsIs(): void
    {
        $this->transport->append(self::mfaPage());

        $before = time();
        $body = (new GenerateMFAHandler(self::D2L_HOST, 123456))->invoke($this->createClient());
        $after = time();

        self::assertSame('X-Csrf-Token', $body->xsrfName);
        self::assertSame('abc123', $body->xsrfCode);
        self::assertSame('123456', $body->mfaCode);
        self::assertContains($body->hitCode, [self::expectedHitCode(7, $before), self::expectedHitCode(7, $after)]);
    }


    public function testGeneratesATotpCodeFromAStringKey(): void
    {
        $this->transport->append(self::mfaPage());

        $before = time();
        $body = (new GenerateMFAHandler(self::D2L_HOST, self::TOTP_KEY))->invoke($this->createClient());
        $after = time();

        $expected = array_map(
            fn(int $time): string => (new TOTP())->setKey(self::TOTP_KEY)->setCurrentTime($time)->generate(),
            [$before, $after]
        );
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $body->mfaCode);
        self::assertContains($body->mfaCode, $expected);
    }


    public function testUsesTheLastXsrfInitLineOnThePage(): void
    {
        $this->transport->append(new Response(200, [], implode("\n", [
            'D2L.LP.Web.Authentication.Xsrf.Init("{\"P\":[\"Old-Name\",\"old\",\"1\"]}");',
            'D2L.LP.Web.Authentication.Xsrf.Init("{\"P\":[\"New-Name\",\"new\",\"2\"]}");',
        ])));

        $body = (new GenerateMFAHandler(self::D2L_HOST, 1))->invoke($this->createClient());

        self::assertSame('New-Name', $body->xsrfName);
        self::assertSame('new', $body->xsrfCode);
    }


    public function testAPageWithoutTheXsrfLineGivesEmptyFieldsAndATimeOnlyHitCode(): void
    {
        $this->transport->append(new Response(200, [], '<html><body>no scripts</body></html>'));

        $before = time();
        $body = (new GenerateMFAHandler(self::D2L_HOST, 1))->invoke($this->createClient());
        $after = time();

        self::assertSame('', $body->xsrfName);
        self::assertSame('', $body->xsrfCode);
        self::assertContains($body->hitCode, [self::expectedHitCode(0, $before), self::expectedHitCode(0, $after)]);
    }


    public function testAnythingButOkIsAResponseError(): void
    {
        $this->transport->append(new Response(302, ['Location' => '/d2l/home']));

        try {
            (new GenerateMFAHandler(self::D2L_HOST, 1))->invoke($this->createClient());
            self::fail('A redirect instead of the MFA page should be an error');
        } catch (ResponseException $e) {
            self::assertStringContainsString('=> 302', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }
}
