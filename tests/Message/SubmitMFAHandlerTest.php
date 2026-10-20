<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Message;

use Brightspace\Api\Core\Message\SubmitMFAHandler;
use Brightspace\Api\Core\Model\LoginUri;
use Brightspace\Api\Core\Model\MfaBody;
use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use GuzzleHttp\Psr7\Response;

final class SubmitMFAHandlerTest extends BrightspaceTestCase
{
    private static function mfaBody(): MfaBody
    {
        return new MfaBody(xsrfName: 'X-Csrf-Token', xsrfCode: 'abc123', hitCode: 42, mfaCode: '654321');
    }


    public function testPostsTheVerifyPinRpcToTheMfaPage(): void
    {
        $this->transport->append(new Response(200, [], '{}'));

        (new SubmitMFAHandler(self::D2L_HOST, self::ROOT_ORG_UNIT, self::mfaBody()))->invoke($this->createClient());

        $sent = $this->sentRequest();
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('lms.example.edu', $sent->getUri()->getHost());
        self::assertSame('/d2l/lp/auth/twofactorauthentication/TwoFactorCodeEntry.d2l', $sent->getUri()->getPath());
        self::assertSame(['ou' => '6606', 'd2l_rh' => 'rpc', 'd2l_rt' => 'call'], self::queryOf($sent));
        self::assertSame('application/x-www-form-urlencoded', $sent->getHeaderLine('Content-Type'));
        self::assertSame([
            'd2l_rf' => 'VerifyPin',
            'params' => '{"param1":"654321"}',
            'X-Csrf-Token' => 'abc123',
            'd2l_hitcode' => '42',
            'd2l_action' => 'rpc',
        ], self::formBodyOf($sent));
    }


    public function testAnAcceptedCodeLeadsToProcessLoginActions(): void
    {
        $this->transport->append(new Response(200, [], '{}'));

        $next = (new SubmitMFAHandler(self::D2L_HOST, self::ROOT_ORG_UNIT, self::mfaBody()))
            ->invoke($this->createClient());

        self::assertSame(LoginUri::PROCESS_LOGIN, $next);
    }


    public function testARejectedCodeMeansNoNextStep(): void
    {
        $this->transport->append(new Response(403, [], 'nope'));

        $next = (new SubmitMFAHandler(self::D2L_HOST, self::ROOT_ORG_UNIT, self::mfaBody()))
            ->invoke($this->createClient());

        self::assertNull($next);
    }
}
