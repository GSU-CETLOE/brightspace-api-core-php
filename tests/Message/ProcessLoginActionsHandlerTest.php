<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Message;

use Brightspace\Api\Core\Message\ProcessLoginActionsHandler;
use Brightspace\Api\Core\Model\LoginUri;
use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use GuzzleHttp\Psr7\Response;

final class ProcessLoginActionsHandlerTest extends BrightspaceTestCase
{
    public function testGetsTheProcessLoginActionsPage(): void
    {
        $this->transport->append(self::redirectTo('/d2l/home'));

        $next = (new ProcessLoginActionsHandler(self::D2L_HOST))->invoke($this->createClient());

        $sent = $this->sentRequest();
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('https://lms.example.edu/d2l/lp/auth/login/ProcessLoginActions.d2l', (string) $sent->getUri());
        self::assertSame(LoginUri::HOME, $next);
    }


    public function testAnUnknownRedirectMeansNoNextStep(): void
    {
        $this->transport->append(self::redirectTo('/d2l/le/somewhere'));

        self::assertNull((new ProcessLoginActionsHandler(self::D2L_HOST))->invoke($this->createClient()));
    }


    public function testAnythingButARedirectMeansNoNextStep(): void
    {
        $this->transport->append(new Response(200, [], 'still here'));

        self::assertNull((new ProcessLoginActionsHandler(self::D2L_HOST))->invoke($this->createClient()));
    }
}
