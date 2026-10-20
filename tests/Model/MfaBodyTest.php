<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Model;

use Brightspace\Api\Core\Model\MfaBody;
use PHPUnit\Framework\TestCase;

final class MfaBodyTest extends TestCase
{
    public function testKeepsItsValues(): void
    {
        $body = new MfaBody(xsrfName: 'X-Csrf-Token', xsrfCode: 'abc123', hitCode: 42, mfaCode: '654321');

        self::assertSame('X-Csrf-Token', $body->xsrfName);
        self::assertSame('abc123', $body->xsrfCode);
        self::assertSame(42, $body->hitCode);
        self::assertSame('654321', $body->mfaCode);
    }


    public function testBuildsTheVerifyPinRpcForm(): void
    {
        $body = new MfaBody(xsrfName: 'X-Csrf-Token', xsrfCode: 'abc123', hitCode: 42, mfaCode: '654321');

        self::assertSame([
            'd2l_rf' => 'VerifyPin',
            'params' => '{"param1":"654321"}',
            'X-Csrf-Token' => 'abc123',
            'd2l_hitcode' => 42,
            'd2l_action' => 'rpc',
        ], $body->getBody());
    }
}
