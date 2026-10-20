# brightspace-api-core-php

The base layer for talking to a D2L Brightspace instance from PHP. It signs in to the Brightspace web UI with a username, password, and (if the account has it) a TOTP or fixed multi-factor code, uses that session to get an OAuth 2.0 authorization code, exchanges the code for an access token, and keeps the token refreshed. On top of that it gives you a `MessageHandler` base class where you write API requests as `d2l://lp/users/whoami` and the handler fills in the host, the `/d2l/api/` prefix, the product version, and the bearer token. Two paging helpers walk Brightspace's `PagedResultSet` and `ObjectListPage` responses. A Symfony bundle registers everything for autowiring and binds the settings to `D2L_*` environment variables.

The product-specific calls (users, enrollments, org units, grades) are not in here. This package is what those packages build on.

CETLOE at Georgia State University maintains this package. It started as `mjfk23/brightspace-api-core`, written by Melody Kimball.

## Requirements

- PHP 8.1
- A `Gadget\Http\Client\Client` from [gadget-http-php](https://github.com/GSU-CETLOE/gadget-http-php), which needs a PSR-18 client, PSR-17 factories, and a PSR-6 cache pool. Guzzle comes with gadget-http-php. The examples use [symfony/cache](https://symfony.com/doc/current/components/cache.html) for the pool; it is only a dev dependency here, so add it to your own project if you want it.
- A Brightspace account that can sign in through the web login page, and an OAuth 2.0 application registered in Brightspace with a client id, client secret, redirect URI, and scope.

## Setup

Install [Composer](https://getcomposer.org/) if you don't have it, then add the package to your project:

```sh
composer require gsu-cetloe/brightspace-api-core-php
```

To work on the library itself, clone the repository and install the development dependencies:

```sh
git clone https://github.com/GSU-CETLOE/brightspace-api-core-php.git
cd brightspace-api-core-php
composer install
```

Your machine does not have to run PHP 8.1. Composer is told to resolve dependencies as if it were PHP 8.1.0 no matter what version runs it, so the lock file always reflects what the library targets. Before a release, run the suite on an actual 8.1 build.

## Development commands

| Command | What it does |
| --- | --- |
| `composer test` | Runs the PHPUnit suite in `tests/`. |
| `composer phpstan` | Runs PHPStan at level 10 over `src/`, `bundle/`, and `tests/`. |
| `composer phpcs` | Checks `src/`, `bundle/`, and `tests/` against PSR-12. |
| `composer check` | Runs all three in order. Run this before you commit. |

Anything generated, such as the PHPStan and PHPUnit caches, lands in `working/`. Git ignores that directory.

## Settings

Everything the clients need lives in one `Config` object:

| Argument | Env var (bundle) | Meaning |
| --- | --- | --- |
| `d2lHost` | `D2L_HOST` | The Brightspace host, without a scheme: `lms.example.edu` |
| `rootOrgUnit` | `D2L_ROOT_ORGUNIT` | The root org unit id. Sent as `ou` when submitting an MFA code |
| `clientId` | `D2L_CLIENT_ID` | OAuth client id |
| `clientSecret` | `D2L_CLIENT_SECRET` | OAuth client secret |
| `redirectUri` | `D2L_REDIRECT_URI` | The redirect URI registered with the OAuth application |
| `scope` | `D2L_SCOPE` | OAuth scope, for example `core:*:*` |
| `username` | `D2L_USERNAME` | The account that signs in through the web login page |
| `password` | `D2L_PASSWORD` | Its password |
| `mfa` | `D2L_MFA_KEY` | Multi-factor setting, see below. Optional |
| `authUri` | | Defaults to `https://auth.brightspace.com/oauth2/auth` |
| `tokenUri` | | Defaults to `https://auth.brightspace.com/core/connect/token` |

The `mfa` setting takes three forms. A string is treated as a TOTP secret (the base32 key you get when enrolling an authenticator app), and a fresh six-digit code is generated at login time. An integer is sent as the code itself, which only makes sense in a test environment. `null`, `0`, or an empty string means the account has no second factor; if Brightspace asks for one anyway, login fails with a `RuntimeException`.

## Building the clients

There are three client classes, and they stack:

- `CoreApiClient` extends gadget-oauth's `AuthClient`. It does the web login and the OAuth calls, using the `Config` for every setting.
- `CoreClient` wraps a `CoreApiClient` and adds the cache: it holds the current token, refreshes it when it expires, and remembers the product version table.
- `ApiClient` is the base for your own API client classes. It takes the `Config` and `CoreClient` and hands them to every Brightspace `MessageHandler` it invokes.

```php
use Brightspace\Api\Core\Client\CoreApiClient;
use Brightspace\Api\Core\Client\CoreClient;
use Brightspace\Api\Core\Config;
use Gadget\Cache\CacheItemPool;
use Gadget\Http\Client\Client;
use Gadget\Http\Message\MessageFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$factory = new HttpFactory();

$http = new Client(
    new GuzzleClient(['allow_redirects' => false, 'http_errors' => false]),
    new MessageFactory($factory, $factory, $factory, $factory, $factory, $factory),
    new CacheItemPool(new FilesystemAdapter())
);

$config = new Config(
    d2lHost: 'lms.example.edu',
    rootOrgUnit: 6606,
    clientId: 'my-client-id',
    clientSecret: 'my-client-secret',
    redirectUri: 'https://app.example.edu/callback',
    scope: 'core:*:*',
    username: 'svc-brightspace',
    password: 'secret',
    mfa: 'JBSWY3DPEHPK3PXP'
);

$coreApi = new CoreApiClient($http, $config);
$core = new CoreClient($http, $coreApi);
```

Use the same `Client` instance for both. The login leaves session cookies in that client's cookie jar, and `createAuthCode()` refuses to run unless it can see them. Two Guzzle options matter: `allow_redirects => false`, because the login flow reads the next step off each `302`'s `Location` header, and `http_errors => false`, because the handlers decide for themselves what a non-200 means.

A persistent cache adapter such as `FilesystemAdapter` keeps the cookie jar and the token between PHP processes, so a console job that runs every few minutes reuses the token from the last run instead of logging in again. How long a web session lasts is up to the Brightspace instance.

## Getting a token

```php
$accessToken = $core->getAccessToken();
```

That one call does whatever is needed. In order: look for a token in the cache; if there is none, log in to the web UI (skipped if the session cookies are still good), get an authorization code, and exchange it; if the cached token has passed `created_on + expires_in`, post a refresh. The result is cached and the access token string comes back. If the token has no refresh token and has expired, or the response has no access token, you get a `RuntimeException`.

You can also drive the steps yourself:

```php
$core->login();                       // true once the session cookies are in place
$authCode = $coreApi->createAuthCode(); // needs a live session
$token = $core->createToken($authCode); // or $core->createToken() to log in and get a code first
$fresh = $core->refreshToken($token->refreshToken);
```

### What the web login does

`CoreApiClient::login()` posts the username and password to `/d2l/lp/auth/login/login.d2l` and reads the next step from the redirect:

| Brightspace redirects to | What happens next |
| --- | --- |
| `/d2l/home` | Done. Returns `true`. |
| `/d2l/lp/auth/login/ProcessLoginActions.d2l` | Fetches that page, then reads its redirect the same way. |
| `/d2l/lp/auth/twofactorauthentication/TwoFactorCodeEntry.d2l` or `/d2l/lp/preferences/authenticatorLoginAction/entercoderedirect` | Fetches the MFA page, pulls the XSRF token and hit code out of its script, posts the MFA code as a `VerifyPin` RPC, then goes through `ProcessLoginActions.d2l`. |
| `/d2l/lp/auth/login/login.d2l` | The credentials were rejected. Returns `false`. |
| anything else, or no redirect at all | `RuntimeException`. |

`login()` takes an optional username, password, MFA setting, root org unit, and host, so one `CoreApiClient` can sign in as someone else. If you pass a username and password, the configured MFA setting is not used; pass your own or none.

`isLoggedIn()` checks for unexpired `d2lSessionVal` and `d2lSecureSessionVal` cookies for the configured host. `CoreClient::login()` calls it first and only runs the web login when it is false.

## Writing an API call

Extend `Brightspace\Api\Core\Message\MessageHandler` instead of gadget-http's. Build the request with a `d2l://` URI and let the handler rewrite it:

```php
use Brightspace\Api\Core\Message\MessageHandler;
use Gadget\Http\Message\RequestBuilder;
use Gadget\Io\Cast;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @extends MessageHandler<array<string,mixed>> */
final class WhoAmIHandler extends MessageHandler
{
    protected function createRequest(RequestBuilder $requestBuilder): ServerRequestInterface
    {
        return $requestBuilder
            ->setMethod('GET')
            ->setUri('d2l://lp/users/whoami')
            ->getRequest();
    }

    protected function handleResponse(
        ResponseInterface $response,
        ServerRequestInterface $request
    ): mixed {
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Status code: " . $response->getStatusCode());
        }
        return $this->jsonToArray($response);
    }
}
```

Before the request goes out, the handler:

1. Rewrites the URI. The host part of a `d2l://` URI is the product code. `d2l://lp/users/whoami` becomes `https://lms.example.edu/d2l/api/lp/1.46/users/whoami`, where `1.46` is the latest version of `lp` according to `/d2l/api/versions/` (looked up once and cached for an hour). The host names `api` and `web`, and any path that already starts with `/d2l/api/`, are placed on the configured host as given. Other schemes are left alone.
2. Adds `Authorization: Bearer <token>` using `CoreClient::getAccessToken()`. Turn this off with `->useAccessToken(false)` for calls that don't want it.
3. Optionally runs the web login first, with `->useWebLogin(true)`. Use that for pages under `/d2l/lp/` that are not part of the REST API and need the session cookies.

Invoke it through an `ApiClient` subclass, which calls `init()` on the handler with the `Config` and `CoreClient` before sending:

```php
use Brightspace\Api\Core\Client\ApiClient;

final class UsersClient extends ApiClient
{
    /** @return array<string,mixed> */
    public function whoAmI(): array
    {
        return $this->invoke(new WhoAmIHandler());
    }
}

$users = new UsersClient($http, $config, $core);
$me = $users->whoAmI();
```

A handler that is invoked without going through an `ApiClient` throws a `RuntimeException` the moment it needs the config or the core client.

## Paging

Brightspace has two paging shapes. `PagedResultSet` is `{ "PagingInfo": { "Bookmark": "...", "HasMoreItems": true }, "Items": [...] }` and you pass the bookmark back to get the next page. `ObjectListPage` is `{ "PagingInfo": "<next url>", "Objects": [...] }` and you follow the URL. Both model classes have a `create()` that takes the decoded body and a callable to turn each raw item into your type, and a static `forEach()` that takes a "fetch the page after this bookmark" callable and yields every item across all pages:

```php
use Brightspace\Api\Core\Model\PagedResultSet;

$allUsers = PagedResultSet::forEach(
    fn(string|null $bookmark): PagedResultSet => $users->listPage($bookmark)
);

foreach ($allUsers as $user) {
    // ...
}
```

`forEach()` stops when the bookmark (or next URL) comes back `null` or empty. `PagingInfo::create()` insists on `HasMoreItems` being present; a body without it is a `CastException`.

## Symfony bundle

Add the bundle to `config/bundles.php` after the gadget bundles it builds on:

```php
return [
    // ...
    Gadget\Cache\GadgetCacheBundle::class => ['all' => true],
    Gadget\Http\GadgetHttpBundle::class => ['all' => true],
    Gadget\Oauth\GadgetOauthBundle::class => ['all' => true],
    Brightspace\Api\Core\BrightspaceApiCoreBundle::class => ['all' => true],
];
```

The bundle registers every class under `Brightspace\Api\Core\` as an autowired, autoconfigured service and binds `Config` to the environment variables in the Settings table above. Put them in `.env.local`:

```sh
D2L_HOST=lms.example.edu
D2L_ROOT_ORGUNIT=6606
D2L_CLIENT_ID=my-client-id
D2L_CLIENT_SECRET=my-client-secret
D2L_REDIRECT_URI=https://app.example.edu/callback
D2L_SCOPE="core:*:*"
D2L_USERNAME=svc-brightspace
D2L_PASSWORD=secret
D2L_MFA_KEY=JBSWY3DPEHPK3PXP
```

`D2L_MFA_KEY` can be left empty. The gadget-http README explains how to point the PSR-18 and PSR-17 interfaces at Guzzle and which cache pool to give it; the two Guzzle options from Building the clients apply there too.

## Errors

| What happened | What you catch |
| --- | --- |
| Login needed MFA and none is configured, the credentials got no redirect, MFA was rejected, or `createAuthCode()` was called with no session | `RuntimeException` from the client |
| A cached token has expired and has no refresh token, or a token came back with no access token | `RuntimeException` from `CoreClient::getAccessToken()` |
| A handler is used without `init()` | `RuntimeException` from the handler |
| The MFA page or `/d2l/api/versions/` answered with something other than `200` | `Gadget\Http\Exception\ResponseException` with the `RuntimeException` as `getPrevious()` |
| The OAuth endpoints misbehaved | `ResponseException` wrapping a `Gadget\Oauth\Exception\AuthException`, as described in the gadget-oauth README |
| The wrapped PSR-18 client threw | `Gadget\Http\Exception\ClientException` |

The exceptions this package throws itself carry no message. If you need to know which step failed, look at the stack trace or at which requests went out.

## Testing

`composer test` runs the suite. Every handler and client goes through a real gadget-http `Client` over Guzzle with a `MockHandler` transport, so the tests check the exact requests, form bodies, cookies, and redirects, and nothing goes over a socket. The extension test compiles a real container on top of the gadget-http extension. [TESTPLAN.md](TESTPLAN.md) has the manual walkthrough, including the steps against a real Brightspace instance.

## License

MIT. See [LICENSE](LICENSE).
