# Test Plan

Walk through this list before tagging a release or merging a change that touches `composer.json`, the analyzers, or the test suite. Run every command from the repository root.

## Setup

- [ ] `composer install` finishes without errors and without asking to change the lock file.
- [ ] `composer validate` reports the manifest is valid.
- [ ] `working/` exists after the first run and is not shown in `git status`.

## Automated checks

- [ ] `composer phpcs` reports no errors and no warnings for `src/`, `bundle/`, and `tests/`.
- [ ] `composer phpstan` reports `No errors` at level 10 for `src/`, `bundle/`, and `tests/`.
- [ ] `composer test` passes with no warnings, deprecations, or notices in the output.
- [ ] `composer check` runs all three in order and exits 0.

## Functional coverage

Confirm each area has passing tests under `tests/` that cover the listed behaviors.

- [ ] `Config`: keeps every value it is given; defaults to the Brightspace auth and token endpoints; `0` and `''` for `mfa` become `null`, a string key and an integer code are kept.
- [ ] `LoginUri`: each case maps to its Brightspace path; `tryFrom()` only matches an exact path, not one with a query string or a scheme.
- [ ] `MfaBody`: keeps its values; `getBody()` is the `VerifyPin` RPC form with the XSRF field under its own name and `d2l_hitcode` as an int.
- [ ] `ProductVersion`: keeps the product code and latest version.
- [ ] `PagingInfo`: defaults to no bookmark and no more items; `create()` reads `Bookmark` and `HasMoreItems` from an array or object and casts the bookmark to a string; a missing bookmark is `null`; a missing `HasMoreItems` or a non-array body is a `CastException`.
- [ ] `PagedResultSet`: `create()` builds the paging info and maps `Items` through the callable; missing `Items` is an empty page; missing `PagingInfo` is a `CastException`; `forEach()` requests `null`, then each bookmark in turn, yields every item, and stops on a `null` or empty bookmark.
- [ ] `ObjectListPage`: `create()` reads `PagingInfo` as the next URL and maps `Objects`; missing fields are a last empty page; `forEach()` follows `next` until it is `null` or empty.
- [ ] `SubmitCredentialsHandler`: posts `d2l_referrer`, `noredirect`, `loginPath`, `userName`, and `password` as a form to `login.d2l` on the given host; maps each known redirect `Location` to its `LoginUri`; an unknown location, a non-redirect, or a redirect without a location is `null`.
- [ ] `ProcessLoginActionsHandler`: gets `ProcessLoginActions.d2l`; a known redirect is its `LoginUri`; anything else is `null`.
- [ ] `SubmitMFAHandler`: posts the `MfaBody` form to the MFA page with `ou`, `d2l_rh=rpc`, and `d2l_rt=call` in the query; `200` is `PROCESS_LOGIN`; anything else is `null`.
- [ ] `GenerateMFAHandler`: gets the MFA page; reads the XSRF name, code, and hit code from the last `D2L.LP.Web.Authentication.Xsrf.Init` line; the hit code is the server's number plus the time in milliseconds modulo 10^8; an integer MFA setting is sent as the code and a string setting produces a six-digit TOTP code; a page without the line gives empty fields and a time-only hit code; anything but `200` is a `ResponseException` caused by a `RuntimeException`.
- [ ] `GetProductVersionsHandler`: gets `/d2l/api/versions/`; maps the response by product code into `ProductVersion` objects; an empty list is an empty map; a non-`200` is a `ResponseException` whose cause says `Status code: NNN`; a malformed body is a `ResponseException`.
- [ ] `MessageHandler`: the config and core client throw `RuntimeException` before `init()` and are returned after it; the bearer header is sent by default and left off with `useAccessToken(false)`; `useWebLogin(true)` runs the web login first and skips it when the session is still valid; `d2l://<product>/<path>` becomes `https://<host>/d2l/api/<product>/<version>/<path>` with the query string kept; the `api` and `web` hosts and paths already under `/d2l/api/` are placed on the configured host unchanged; other schemes are untouched; the product version is looked up when it is not cached.
- [ ] `ApiClient`: exposes the config and core client it was built with; `invoke()` calls `init()` on a Brightspace handler before sending; a plain gadget handler still runs.
- [ ] `CoreApiClient`: `isLoggedIn()` is true only when both session cookies exist, are unexpired, and belong to the configured host; `login()` submits the configured or given credentials and host, follows `ProcessLoginActions`, completes MFA through the pre-MFA and MFA redirects with the configured code, fails with `RuntimeException` when MFA is needed but not configured, when explicit credentials are given without a code, when MFA is rejected, when the credentials get no redirect, or when `ProcessLoginActions` leads nowhere, and returns `false` when sent back to the login page; `createAuthCode()` refuses without a session and otherwise asks the configured auth endpoint with the configured client settings; `createToken()` posts the code to the configured token endpoint; `getProductVersions()` asks the configured or given host; the session cookies land in the wrapped client's jar.
- [ ] `CoreClient`: `getProductVersion()` looks the table up once, caches it, serves later calls from the cache, and falls back to `1.0` for an unknown product; `getAccessToken()` returns a valid cached token without a request, refreshes one that has passed `created_on + expires_in`, fails when an expired token has no refresh token or a token has no access token, and logs in, gets a code, and creates a token when nothing is cached; `login()` skips the web login while the session is valid; `createToken()` exchanges a given code or logs in to get one; `refreshToken()` posts the refresh grant.
- [ ] `BrightspaceApiCoreBundle`: extends `Bundle` and exposes `BrightspaceApiCoreExtension` under the alias `brightspace_api_core`.
- [ ] `BrightspaceApiCoreExtension`: registers `Config`, `ApiClient`, `CoreApiClient`, and `CoreClient` as autowired, autoconfigured services; binds every `Config` argument to its `D2L_*` env var; a container compiled with the gadget-http extension, Guzzle under the PSR interfaces, a PSR-6 pool, and the env vars set builds a `Config`, a `CoreClient`, and an `ApiClient`.

## Consuming the package

- [ ] In a scratch project, `composer require gsu-cetloe/brightspace-api-core-php` installs `gsu-cetloe/gadget-console-php`, `gsu-cetloe/gadget-http-php`, `gsu-cetloe/gadget-oauth-php`, `gsu-cetloe/symfony-bundle`, and Guzzle.
- [ ] Against a real Brightspace instance, with the Guzzle client built with `allow_redirects => false` and `http_errors => false`, a `FilesystemAdapter` cache, and a service account without MFA, `CoreClient::getAccessToken()` returns an access token and the next run returns the same token without logging in again.
- [ ] Against that instance with an account enrolled in an authenticator app and its TOTP secret in `mfa`, `CoreApiClient::login()` returns `true`.
- [ ] Against that instance, a `MessageHandler` built with `d2l://lp/users/whoami` returns the service account's user record, and the request went to `/d2l/api/lp/<latest lp version>/users/whoami`.
- [ ] After the cached token's `expires_in` has passed, the next `getAccessToken()` posts a refresh and returns a new token.
- [ ] In a scratch Symfony app with the four bundles enabled and the `D2L_*` variables set, a service that type-hints `Brightspace\Api\Core\Client\CoreClient` autowires and `getAccessToken()` works.

## Known gaps

- The transport in the suite is Guzzle's `MockHandler`. The requests Guzzle would send are checked exactly, but nothing goes over a socket and no real Brightspace instance is involved. The manual steps above cover the real wire.
- The MFA page fixture in the suite is a cut-down version of what Brightspace serves. If D2L changes the `D2L.LP.Web.Authentication.Xsrf.Init` line or the `VerifyPin` RPC, the suite will still pass. The manual MFA step above is the only check for that.
- `LoginUri::tryFrom()` needs an exact match, so a redirect to `/d2l/home?ou=6606` is treated as unknown and `login()` throws. The suite pins the current behavior.
- `GenerateMFAHandler` reads the TOTP code at the moment the page is fetched. A code generated in the last second of a 30-second window may be stale by the time it is submitted. The suite accepts the code for either second around the call and does not test the window edge.
- `gsu-cetloe/gadget-console-php` is required but nothing in `src/` or `bundle/` uses it. The original package required it too. Whether any downstream package relies on it arriving through this one has not been checked.
- Tests run on whatever PHP is installed locally. Composer resolves dependencies as if PHP were 8.1.0 and PHPStan analyzes for 8.1, but the behavior checked here is that of the local interpreter.
