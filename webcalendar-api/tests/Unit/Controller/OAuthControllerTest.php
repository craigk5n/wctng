<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Auth\OAuthProvider;
use App\Auth\OAuthProviderRepository;
use App\Controller\Api\OAuthController;
use App\Security\OutboundUrlValidator;
use App\Security\UserTokenIndex;
use App\Service\CoreServiceFactory;
use App\Tenant\Tenant;
use App\Tenant\TenantContext;
use App\Tenant\TenantPlan;
use App\Tenant\TenantStatus;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OAuth sign-in flow.
 *
 * Nothing executed a line of this controller: the only test that named it
 * checked by reflection that one parameter carries #[\SensitiveParameter],
 * which never calls it. Both of its routes are live -- the redirect a browser
 * is sent to, and the callback that turns a provider's code into a session on
 * this system -- so every decision in between was unpinned, including whether
 * the PKCE challenge matches the verifier handed to the client.
 */
final class OAuthControllerTest extends TestCase
{
    private const string NOW = '2026-04-01T09:00:00+00:00';
    private const int TTL = 3600;

    private \PDO $pdo;
    private CoreServiceFactory $factory;
    private OAuthProviderRepository $providers;
    private UserTokenIndex $tokenIndex;
    private TenantContext $context;
    /** @var list<array<string, mixed>> */
    private array $encoded = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(
            __DIR__ . '/../../../vendor/craigk5n/webcalendar-core/src/Infrastructure/Persistence/sqlite-schema.sql',
        );
        self::assertIsString($schema);
        foreach (preg_split('/;\s*\n/', (string) preg_replace('/--[^\n]*/', '', $schema)) ?: [] as $statement) {
            if (trim($statement) !== '') {
                try {
                    $this->pdo->exec($statement);
                } catch (\PDOException) {
                    // Not every statement applies to this SQLite build.
                }
            }
        }

        $this->factory = new CoreServiceFactory($this->pdo, 'oauth_test_secret_32_characters!');
        $this->providers = new OAuthProviderRepository($this->pdo);
        $this->tokenIndex = new UserTokenIndex($this->pdo, new MockClock(self::NOW));
        $this->context = new TenantContext();
        $this->encoded = [];
    }

    private function controller(): OAuthController
    {
        $encoder = $this->createMock(JWTEncoderInterface::class);
        $encoder->method('encode')->willReturnCallback(function (array $claims): string {
            $this->encoded[] = $claims;

            return 'signed.' . count($this->encoded);
        });

        return new OAuthController(
            $this->providers,
            $this->factory->getUserService(),
            $this->factory->getUserRepository(),
            $encoder,
            $this->context,
            self::TTL,
            new MockClock(self::NOW),
            $this->tokenIndex,
            new OutboundUrlValidator('standalone'),
        );
    }

    private function saveProvider(
        bool $enabled = true,
        string $tokenUrl = 'https://provider.test/token',
        string $userinfoUrl = 'https://provider.test/userinfo',
    ): int {
        return $this->providers->save(new OAuthProvider(
            id: 0,
            name: 'Test IdP',
            type: 'oauth2',
            clientId: 'client-abc',
            clientSecret: 'secret-xyz',
            authUrl: 'https://provider.test/authorize',
            tokenUrl: $tokenUrl,
            userinfoUrl: $userinfoUrl,
            scopes: 'openid email profile',
            enabled: $enabled,
        ));
    }

    /** @return array<string, mixed> */
    private static function dataOf(Response $response): array
    {
        /** @var array{data: array<string, mixed>} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['data'];
    }

    private function callbackWith(int $providerId, string $body): Response
    {
        return $this->controller()->callback(
            $providerId,
            Request::create('/api/v2/auth/oauth/' . $providerId . '/callback', 'POST', [], [], [], [], $body),
        );
    }

    // --------------------------------------------------------- the redirect

    public function testTheRedirectRefusesAProviderThatIsNotThere(): void
    {
        $response = $this->controller()->redirect(999, Request::create('/'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTheRedirectRefusesAProviderThatIsSwitchedOff(): void
    {
        // A disabled provider must not be a way in, or turning one off in the
        // admin screen would leave the door open.
        $id = $this->saveProvider(enabled: false);

        self::assertSame(404, $this->controller()->redirect($id, Request::create('/'))->getStatusCode());
    }

    public function testTheChallengeIsTheHashOfTheVerifierItHandsBack(): void
    {
        // This is what PKCE is: the client keeps the verifier, the provider
        // gets its S256 hash, and only the holder of the verifier can redeem
        // the code. If the two stopped agreeing -- a different hash, the raw
        // verifier, plain base64 rather than base64url -- the provider would
        // reject every exchange, and if the method said "plain" the protection
        // would be gone while everything still worked.
        $id = $this->saveProvider();

        $data = self::dataOf($this->controller()->redirect($id, Request::create('https://cal.example/')));

        $query = [];
        parse_str((string) parse_url((string) $data['auth_url'], \PHP_URL_QUERY), $query);

        $expected = rtrim(strtr(base64_encode(hash('sha256', (string) $data['code_verifier'], true)), '+/', '-_'), '=');
        self::assertSame($expected, $query['code_challenge'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertStringNotContainsString('=', (string) $query['code_challenge'], 'base64url is unpadded');
    }

    public function testTheRedirectCarriesEverythingTheProviderNeeds(): void
    {
        $id = $this->saveProvider();

        $data = self::dataOf($this->controller()->redirect($id, Request::create('https://cal.example/')));
        $url = (string) $data['auth_url'];

        self::assertStringStartsWith('https://provider.test/authorize?', $url);

        $query = [];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('client-abc', $query['client_id'] ?? null);
        self::assertSame('openid email profile', $query['scope'] ?? null);
        self::assertSame('https://cal.example/api/v2/auth/oauth/' . $id . '/callback', $query['redirect_uri'] ?? null);
        self::assertSame($data['state'], $query['state'] ?? null, 'the client is told the state to check on the way back');
    }

    public function testEveryRedirectGetsItsOwnStateAndVerifier(): void
    {
        // Reusing either across sign-ins would let one attempt's code be
        // redeemed by another.
        $id = $this->saveProvider();

        $first = self::dataOf($this->controller()->redirect($id, Request::create('https://cal.example/')));
        $second = self::dataOf($this->controller()->redirect($id, Request::create('https://cal.example/')));

        self::assertNotSame($first['state'], $second['state']);
        self::assertNotSame($first['code_verifier'], $second['code_verifier']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $first['code_verifier']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $first['state']);
    }

    // --------------------------------------------------------- the callback

    public function testTheCallbackRefusesAProviderThatIsNotThere(): void
    {
        self::assertSame(404, $this->callbackWith(999, '{"code":"abc"}')->getStatusCode());
    }

    public function testTheCallbackRefusesAProviderThatIsSwitchedOff(): void
    {
        $id = $this->saveProvider(enabled: false);

        self::assertSame(404, $this->callbackWith($id, '{"code":"abc"}')->getStatusCode());
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $id = $this->saveProvider();

        self::assertSame(400, $this->callbackWith($id, 'not json')->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesWithoutACode(): iterable
    {
        yield 'no code at all' => ['{"code_verifier":"v"}'];
        yield 'an empty code' => ['{"code":""}'];
        yield 'a code that is not a string' => ['{"code":123}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bodiesWithoutACode')]
    public function testTheCallbackNeedsAnAuthorizationCode(string $body): void
    {
        $id = $this->saveProvider();

        $response = $this->callbackWith($id, $body);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->encoded, 'no token should be issued');
    }

    public function testAnExchangeThatFailsIsNotASignIn(): void
    {
        // Nothing is listening on port 9, so the token request cannot succeed.
        // The caller gets a 401 rather than an exception, and no session.
        $id = $this->saveProvider(tokenUrl: 'http://127.0.0.1:9/token');

        $response = $this->callbackWith($id, '{"code":"abc","code_verifier":"v"}');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->encoded);
    }

    public function testAProviderWithNoTokenUrlIsNotASignInEither(): void
    {
        $id = $this->saveProvider(tokenUrl: '');

        self::assertSame(401, $this->callbackWith($id, '{"code":"abc"}')->getStatusCode());
    }

    // ------------------------------------------------- a whole sign-in

    /** @var resource|null */
    private $server;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $dir = '';

    #[\Override]
    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = [];
            proc_close($this->server);
        }

        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    /** Starts a provider that answers the token and userinfo calls. */
    private function stubProvider(): string
    {
        $this->dir = sys_get_temp_dir() . '/wctng_oauth_' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $router = $this->dir . '/router.php';
        file_put_contents($router, <<<'ROUTER'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            header('Content-Type: application/json');

            if ($path === '/token') {
                file_put_contents(__DIR__ . '/token-request.json', json_encode([
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'body' => file_get_contents('php://input'),
                ]));
                echo json_encode(['access_token' => 'at-123', 'token_type' => 'Bearer']);

                return true;
            }

            if ($path === '/userinfo') {
                file_put_contents(__DIR__ . '/userinfo-request.json', json_encode([
                    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
                ]));
                echo json_encode([
                    'sub' => '1234567890',
                    'email' => 'Alice.Smith@Example.COM',
                    'name' => 'Alice Smith',
                ]);

                return true;
            }

            http_response_code(404);
            echo '{}';

            return true;
            ROUTER);

        $port = self::freePort();
        $server = proc_open(
            ['php', '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server, 'could not start the stub provider');
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->pipes = $pipes;
        $this->server = $server;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);

                return 'http://127.0.0.1:' . $port;
            }

            usleep(50_000);
        }

        self::fail('the stub provider never accepted a connection');
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock);
        $name = stream_socket_get_name($sock, false);
        self::assertIsString($name);
        fclose($sock);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function stubProviderWithoutEmail(): string
    {
        $base = $this->stubProvider();
        file_put_contents($this->dir . '/router.php', <<<'ROUTER'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            header('Content-Type: application/json');
            if ($path === '/token') {
                echo json_encode(['access_token' => 'at-123']);

                return true;
            }
            if ($path === '/userinfo') {
                echo json_encode(['sub' => 'subject-only-1234']);

                return true;
            }
            http_response_code(404);
            echo '{}';

            return true;
            ROUTER);

        return $base;
    }

    private function signIn(): Response
    {
        $base = $this->stubProvider();
        $id = $this->saveProvider(tokenUrl: $base . '/token', userinfoUrl: $base . '/userinfo');

        return $this->callbackWith($id, '{"code":"auth-code","code_verifier":"the-verifier"}');
    }

    public function testASuccessfulSignInProvisionsTheUserAndIssuesAToken(): void
    {
        $response = $this->signIn();

        self::assertSame(200, $response->getStatusCode());
        $data = self::dataOf($response);

        // The login is taken from the part before the @, lowercased.
        self::assertSame('alice.smith', $data['user']['login']);
        self::assertSame('Alice', $data['user']['firstname']);
        self::assertSame('Smith', $data['user']['lastname']);
        self::assertSame('Alice.Smith@Example.COM', $data['user']['email']);
        self::assertFalse($data['user']['is_admin'], 'a provisioned user is not an administrator');

        // And the user really exists afterwards.
        self::assertNotNull($this->factory->getUserService()->getUserByLogin('alice.smith'));
    }

    public function testTheTokenCarriesTheClaimsTheRestOfTheApiReadsOffIt(): void
    {
        $this->signIn();

        self::assertCount(1, $this->encoded);
        $claims = $this->encoded[0];

        self::assertSame('alice.smith', $claims['username']);
        self::assertFalse($claims['is_admin']);
        self::assertSame('access', $claims['typ']);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), $claims['iat']);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp() + self::TTL, $claims['exp']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', (string) $claims['jti']);
        self::assertArrayNotHasKey('tenant', $claims, 'no tenant is resolved here');
    }

    public function testTheIssuedTokenIsRecordedSoItCanBeRevoked(): void
    {
        // Logging out everywhere works by finding a user's jtis. A token that
        // was never recorded cannot be revoked.
        $this->signIn();

        $jti = (string) $this->encoded[0]['jti'];
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM webcal_user_jti WHERE jti = :jti AND login = :login');
        $stmt->execute(['jti' => $jti, 'login' => 'alice.smith']);

        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testTheProviderIsAskedForTheProfileWithTheTokenItJustIssued(): void
    {
        $this->signIn();

        $raw = file_get_contents($this->dir . '/userinfo-request.json');
        self::assertIsString($raw, 'the profile was never fetched');
        /** @var array{authorization: string} $seen */
        $seen = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('Bearer at-123', $seen['authorization']);
    }

    public function testTheCodeAndVerifierAreSentToTheTokenEndpoint(): void
    {
        // Without the verifier the provider rejects the exchange, and without
        // the secret it will not talk to us at all.
        $this->signIn();

        $raw = file_get_contents($this->dir . '/token-request.json');
        self::assertIsString($raw);
        /** @var array{method: string, body: string} $seen */
        $seen = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('POST', $seen['method']);
        $sent = [];
        parse_str($seen['body'], $sent);
        self::assertSame('authorization_code', $sent['grant_type'] ?? null);
        self::assertSame('auth-code', $sent['code'] ?? null);
        self::assertSame('the-verifier', $sent['code_verifier'] ?? null);
        self::assertSame('client-abc', $sent['client_id'] ?? null);
        self::assertSame('secret-xyz', $sent['client_secret'] ?? null);
    }

    public function testSigningInTwiceReusesTheSameAccountRatherThanMakingAnother(): void
    {
        $base = $this->stubProvider();
        $id = $this->saveProvider(tokenUrl: $base . '/token', userinfoUrl: $base . '/userinfo');

        $this->callbackWith($id, '{"code":"one","code_verifier":"v"}');
        $this->callbackWith($id, '{"code":"two","code_verifier":"v"}');

        $count = $this->pdo->query("SELECT COUNT(*) FROM webcal_user WHERE cal_login = 'alice.smith'")?->fetchColumn();
        self::assertSame(1, (int) $count);
        self::assertCount(2, $this->encoded, 'but each sign-in gets its own token');
        self::assertNotSame($this->encoded[0]['jti'], $this->encoded[1]['jti']);
    }

    public function testAProfileWithOnlyASubjectCannotYetBeProvisioned(): void
    {
        // Characterisation, and a bug. The guard above deliberately accepts a
        // profile carrying a subject but no email -- it refuses only when both
        // are missing -- and takes the login from the subject. The account is
        // still built with an empty email, which User's constructor rejects,
        // and that happens outside provisionOrFindUser()'s try, so the
        // exception leaves the controller entirely rather than becoming the
        // error envelope every other failure here returns. A provider
        // configured without the email scope can never sign anybody in for the
        // first time. Fixing it means deciding what address such an account
        // should carry, which is a product question rather than a test one.
        $base = $this->stubProviderWithoutEmail();
        $id = $this->saveProvider(tokenUrl: $base . '/token', userinfoUrl: $base . '/userinfo');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address');
        $this->callbackWith($id, '{"code":"abc","code_verifier":"v"}');
    }

    public function testATenantInContextIsNamedInTheTokenAndTheReply(): void
    {
        $this->context->setTenant(
            new Tenant(1, 'acme', 'Acme', '', ':memory:', '', '', TenantPlan::Pro, TenantStatus::Active),
        );

        $data = self::dataOf($this->signIn());

        self::assertSame('acme', $data['tenant']);
        self::assertSame('acme', $this->encoded[0]['tenant']);
    }
}
