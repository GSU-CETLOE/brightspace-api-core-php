<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Message;

use Brightspace\Api\Core\Message\GetProductVersionsHandler;
use Brightspace\Api\Core\Model\ProductVersion;
use Brightspace\Api\Core\Tests\Support\BrightspaceTestCase;
use Gadget\Http\Exception\ResponseException;
use GuzzleHttp\Psr7\Response;

final class GetProductVersionsHandlerTest extends BrightspaceTestCase
{
    public function testGetsTheVersionsEndpoint(): void
    {
        $this->transport->append(self::productVersionsResponse([]));

        (new GetProductVersionsHandler(self::D2L_HOST))->invoke($this->createClient());

        $sent = $this->sentRequest();
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('https://lms.example.edu/d2l/api/versions/', (string) $sent->getUri());
    }


    public function testMapsTheResponseByProductCode(): void
    {
        $this->transport->append(self::productVersionsResponse(['lp' => '1.46', 'le' => '1.79', 'bas' => '1.1']));

        $versions = (new GetProductVersionsHandler(self::D2L_HOST))->invoke($this->createClient());

        self::assertSame(['lp', 'le', 'bas'], array_keys($versions));
        self::assertContainsOnlyInstancesOf(ProductVersion::class, $versions);
        self::assertSame('lp', $versions['lp']->ProductCode);
        self::assertSame('1.46', $versions['lp']->LatestVersion);
        self::assertSame('1.79', $versions['le']->LatestVersion);
    }


    public function testAnEmptyListIsAnEmptyMap(): void
    {
        $this->transport->append(self::productVersionsResponse([]));

        self::assertSame([], (new GetProductVersionsHandler(self::D2L_HOST))->invoke($this->createClient()));
    }


    public function testAnythingButOkIsAResponseErrorNamingTheStatus(): void
    {
        $this->transport->append(new Response(503, [], 'down'));

        try {
            (new GetProductVersionsHandler(self::D2L_HOST))->invoke($this->createClient());
            self::fail('A 503 should be an error');
        } catch (ResponseException $e) {
            $cause = $e->getPrevious();
            self::assertInstanceOf(\RuntimeException::class, $cause);
            self::assertSame('Status code: 503', $cause->getMessage());
        }
    }


    public function testAMalformedBodyIsAResponseError(): void
    {
        $this->transport->append(new Response(200, [], 'not json'));

        $this->expectException(ResponseException::class);

        (new GetProductVersionsHandler(self::D2L_HOST))->invoke($this->createClient());
    }
}
