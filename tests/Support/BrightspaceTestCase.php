<?php

declare(strict_types=1);

namespace Brightspace\Api\Core\Tests\Support;

use Brightspace\Api\Core\Client\CoreApiClient;
use Brightspace\Api\Core\Client\CoreClient;
use Brightspace\Api\Core\Config;
use Gadget\Cache\CacheItemPool;
use Gadget\Http\Client\Client;
use Gadget\Http\Message\MessageFactory;
use Gadget\Oauth\Model\Token;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Builds the gadget-http Client on a real Guzzle client whose transport is a MockHandler queue.
 * Guzzle is told not to follow redirects and not to throw on 4xx/5xx, so the handlers see every
 * raw response exactly as Brightspace would send it. Every request Guzzle would have put on the
 * wire is recorded in $sentRequests. All clients built by one test share one in-memory cache, so
 * a CoreClient and the CoreApiClient behind it see the same cookie jar and token.
 */
abstract class BrightspaceTestCase extends TestCase
{
    protected const D2L_HOST = 'lms.example.edu';
    protected const ROOT_ORG_UNIT = 6606;
    protected const REDIRECT_URI = 'https://app.example.edu/callback';

    protected MockHandler $transport;

    /** @var RequestInterface[] */
    protected array $sentRequests = [];

    protected GuzzleClient $guzzle;

    protected ArrayAdapter $adapter;


    protected function setUp(): void
    {
        $this->transport = new MockHandler();
        $this->sentRequests = [];

        $stack = HandlerStack::create($this->transport);
        $stack->push(fn(callable $next): callable => function (RequestInterface $request, array $options) use ($next) {
            $this->sentRequests[] = $request;
            return $next($request, $options);
        });

        $this->guzzle = new GuzzleClient([
            'handler' => $stack,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
        $this->adapter = new ArrayAdapter();
    }


    /**
     * Builds a Client over the test transport and the shared in-memory cache.
     *
     * @return Client
     */
    protected function createClient(): Client
    {
        $factory = new HttpFactory();
        return new Client(
            $this->guzzle,
            new MessageFactory($factory, $factory, $factory, $factory, $factory, $factory),
            new CacheItemPool($this->adapter)
        );
    }


    /**
     * Builds a Config with sensible test values. Pass a host or an MFA setting to override those.
     *
     * @param string $d2lHost
     * @param int|string|null $mfa
     * @return Config
     */
    protected static function createConfig(
        string $d2lHost = self::D2L_HOST,
        int|string|null $mfa = null
    ): Config {
        return new Config(
            d2lHost: $d2lHost,
            rootOrgUnit: self::ROOT_ORG_UNIT,
            clientId: 'client-id',
            clientSecret: 'client-secret',
            redirectUri: self::REDIRECT_URI,
            scope: 'core:*:*',
            username: 'svc-user',
            password: 'secret',
            mfa: $mfa
        );
    }


    /**
     * Builds the 302 response Brightspace sends to move the browser along.
     *
     * @param string $location
     * @param array<string,string|string[]> $headers
     * @return Response
     */
    protected static function redirectTo(string $location, array $headers = []): Response
    {
        return new Response(302, ['Location' => $location] + $headers);
    }


    /**
     * Builds a 200 JSON response.
     *
     * @param mixed[] $body
     * @return Response
     */
    protected static function jsonResponse(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }


    /**
     * Builds the JSON body Brightspace returns from /d2l/api/versions/.
     *
     * @param array<string,string> $versions product code => latest version
     * @return Response
     */
    protected static function productVersionsResponse(array $versions): Response
    {
        $body = [];
        foreach ($versions as $productCode => $latestVersion) {
            $body[] = ['ProductCode' => $productCode, 'LatestVersion' => $latestVersion];
        }
        return self::jsonResponse($body);
    }


    /**
     * Returns the request that reached the transport at the given position.
     *
     * @param int $index
     * @return RequestInterface
     */
    protected function sentRequest(int $index = 0): RequestInterface
    {
        self::assertArrayHasKey($index, $this->sentRequests, "No request reached the transport at position {$index}");
        return $this->sentRequests[$index];
    }


    /**
     * Parses the query string of a request into name => value pairs.
     *
     * @param RequestInterface $request
     * @return array<array-key,mixed>
     */
    protected static function queryOf(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);
        return $query;
    }


    /**
     * Parses a form-encoded request body into name => value pairs.
     *
     * @param RequestInterface $request
     * @return array<array-key,mixed>
     */
    protected static function formBodyOf(RequestInterface $request): array
    {
        parse_str((string) $request->getBody(), $body);
        return $body;
    }

    /**
     * Returns the cache namespace the gadget-http Client keeps its cookie jar in and the
     * CoreClient keeps its token and product versions in, so a test can seed or inspect it.
     *
     * @return CacheItemPool
     */
    protected function clientCache(): CacheItemPool
    {
        return (new CacheItemPool($this->adapter))->withNamespace(Client::class);
    }


    /**
     * Builds a CoreApiClient and the CoreClient on top of it, both over the same Client so they
     * share one cookie jar, exactly as Symfony wires them.
     *
     * @param Client $client
     * @param Config|null $config
     * @return CoreClient
     */
    protected function createCoreClient(Client $client, Config|null $config = null): CoreClient
    {
        return new CoreClient($client, new CoreApiClient($client, $config ?? self::createConfig()));
    }


    /**
     * Puts the two Brightspace session cookies into the client's jar as a completed web login would.
     *
     * @param Client $client
     * @param int|null $expires unix time the cookies expire, or null for the jar's default lifetime
     * @return void
     */
    protected static function markLoggedIn(Client $client, int|null $expires = null): void
    {
        foreach (['d2lSessionVal', 'd2lSecureSessionVal'] as $name) {
            $client->getCookieJar()->setCookie([
                'Name' => $name,
                'Value' => 'session-' . $name,
                'Domain' => self::D2L_HOST,
                'Path' => '/',
                'Expires' => $expires,
            ]);
        }
    }


    /**
     * The Set-Cookie headers Brightspace sends with a successful credential submission.
     *
     * @return array<string,string[]>
     */
    protected static function sessionCookieHeaders(): array
    {
        return [
            'Set-Cookie' => [
                'd2lSessionVal=session-value; Path=/; Secure; HttpOnly',
                'd2lSecureSessionVal=secure-session-value; Path=/; Secure; HttpOnly',
            ],
        ];
    }


    /**
     * Builds a token as the OAuth token endpoint would return it, created now and good for an hour.
     *
     * @param string|null $accessToken
     * @param string|null $refreshToken
     * @param int $createdOn
     * @param int $expiresIn
     * @return Token
     */
    protected static function token(
        string|null $accessToken = 'the-access-token',
        string|null $refreshToken = 'the-refresh-token',
        int $createdOn = 0,
        int $expiresIn = 3600
    ): Token {
        return new Token(
            type: 'Bearer',
            scope: 'core:*:*',
            createdOn: $createdOn > 0 ? $createdOn : time(),
            expiresIn: $expiresIn,
            accessToken: $accessToken,
            refreshToken: $refreshToken
        );
    }


    /**
     * Builds the JSON body the Brightspace token endpoint returns.
     *
     * @param string $accessToken
     * @param string $refreshToken
     * @return Response
     */
    protected static function tokenResponse(
        string $accessToken = 'the-access-token',
        string $refreshToken = 'the-refresh-token'
    ): Response {
        return self::jsonResponse([
            'token_type' => 'Bearer',
            'scope' => 'core:*:*',
            'expires_in' => 3600,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ]);
    }


    /**
     * Answers an authorization request with a redirect to the redirect URI that carries the given
     * code and echoes back whatever state the client sent, as the real server does.
     *
     * @param string $code
     * @return \Closure(RequestInterface):Response
     */
    protected static function authCodeRedirect(string $code = 'the-auth-code'): \Closure
    {
        return function (RequestInterface $request) use ($code): Response {
            $state = self::queryOf($request)['state'] ?? '';
            return self::redirectTo(self::REDIRECT_URI . '?' . http_build_query([
                'code' => $code,
                'state' => is_string($state) ? $state : '',
            ]));
        };
    }
}
