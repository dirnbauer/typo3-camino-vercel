<?php

declare(strict_types=1);

namespace Webconsulting\Typo3Vercel\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        foreach ($this->environmentNames() as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name]);
        }
        foreach (['HTTP_X_VERCEL_OIDC_TOKEN', 'X_VERCEL_OIDC_TOKEN'] as $name) {
            if (array_key_exists($name, $_SERVER)) {
                $this->originalServer[$name] = $_SERVER[$name];
            }
            unset($_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
        foreach (['HTTP_X_VERCEL_OIDC_TOKEN', 'X_VERCEL_OIDC_TOKEN'] as $name) {
            if (array_key_exists($name, $this->originalServer)) {
                $_SERVER[$name] = $this->originalServer[$name];
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    public function testParsesPostgresDatabaseUrl(): void
    {
        $database = \typo3_vercel_database_config_from_url(
            'postgresql://user%40example:p%40ss@db.example.test:5433/camino?sslmode=require'
        );

        self::assertSame('pdo_pgsql', $database['driver']);
        self::assertSame('user@example', $database['user']);
        self::assertSame('p@ss', $database['password']);
        self::assertSame('db.example.test', $database['host']);
        self::assertSame(5433, $database['port']);
        self::assertSame('camino', $database['dbname']);
        self::assertSame('require', $database['sslmode']);
    }

    public function testParsesMySqlAndSqliteDatabaseUrls(): void
    {
        $mysql = \typo3_vercel_database_config_from_url('mysql://typo3:secret@db.example.test:4000/camino');
        self::assertSame('mysqli', $mysql['driver']);
        self::assertSame(4000, $mysql['port']);
        self::assertSame('camino', $mysql['dbname']);

        $sqlite = \typo3_vercel_database_config_from_url('sqlite:////tmp/typo3/camino.sqlite');
        self::assertSame('pdo_sqlite', $sqlite['driver']);
        self::assertSame('/tmp/typo3/camino.sqlite', $sqlite['path']);
    }

    public function testMySqlUrlIgnoresNonMySqlDriverOverride(): void
    {
        // The image bakes TYPO3_DB_DRIVER=pdo_sqlite as its demo default; a
        // mysql:// DATABASE_URL must not inherit it as a hybrid config.
        $this->setEnv('TYPO3_DB_DRIVER', 'pdo_sqlite');

        $mysql = \typo3_vercel_database_config_from_url('mysql://typo3:secret@db.example.test:3306/camino');
        self::assertSame('mysqli', $mysql['driver']);
        self::assertArrayNotHasKey('path', $mysql);

        $this->setEnv('TYPO3_DB_DRIVER', 'pdo_mysql');
        $mysql = \typo3_vercel_database_config_from_url('mysql://typo3:secret@db.example.test:3306/camino');
        self::assertSame('pdo_mysql', $mysql['driver']);
    }

    public function testParsesTlsRedisUrlWithoutLeakingCredentialsIntoHost(): void
    {
        $this->setEnv('REDIS_URL', 'rediss://default:p%40ss@redis.example.test:6380/3');

        $options = \typo3_vercel_redis_cache_base_options();

        self::assertIsArray($options);
        self::assertSame('tls://redis.example.test', $options['hostname']);
        self::assertSame(6380, $options['port']);
        self::assertSame(3, $options['database']);
        self::assertSame('default', $options['username']);
        self::assertSame('p@ss', $options['password']);
    }

    public function testRedisBackendDefaultsToHardenedPersistentConnections(): void
    {
        $this->setEnv('REDIS_URL', 'rediss://default:secret@redis.example.test:6380/0');

        $configuration = \typo3_vercel_redis_cache_configuration('pages');

        self::assertSame(
            'Webconsulting\\Typo3VercelStorage\\Cache\\Backend\\VercelRedisBackend',
            $configuration['backend'],
        );
        self::assertTrue($configuration['options']['persistentConnection']);
        self::assertSame(2, $configuration['options']['readTimeout']);

        $this->setEnv('TYPO3_REDIS_PERSISTENT_CONNECTION', '0');
        $configuration = \typo3_vercel_redis_cache_configuration('pages');
        self::assertFalse($configuration['options']['persistentConnection']);
    }

    public function testMovesBackendSessionsToRedisWithKillSwitch(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The Redis extension is not installed.');
        }

        $this->setEnv('TYPO3_CACHE_BACKEND', 'redis');
        $this->setEnv('REDIS_URL', 'rediss://default:p%40ss@redis.example.test:6380/3');

        $session = \typo3_vercel_session_configuration();

        self::assertSame(
            'TYPO3\\CMS\\Core\\Session\\Backend\\RedisSessionBackend',
            $session['BE']['backend'],
        );
        self::assertSame('tls://redis.example.test', $session['BE']['options']['hostname']);
        self::assertSame(6380, $session['BE']['options']['port']);
        self::assertSame(3, $session['BE']['options']['database']);
        self::assertSame('default', $session['BE']['options']['username']);
        self::assertSame('p@ss', $session['BE']['options']['password']);

        $this->setEnv('TYPO3_REDIS_SESSIONS', '0');
        self::assertSame([], \typo3_vercel_session_configuration());
    }

    public function testPersistsNetworkDatabaseConnectionsForTheRuntimeOnly(): void
    {
        $this->setEnv('DATABASE_URL', 'postgresql://typo3:secret@db.example.test:5432/camino');

        $database = \typo3_vercel_database_runtime_config();
        self::assertTrue($database['driverOptions'][\PDO::ATTR_PERSISTENT]);
        self::assertArrayNotHasKey('driverOptions', \typo3_vercel_database_config());

        $this->setEnv('TYPO3_DB_PERSISTENT_CONNECTION', '0');
        $database = \typo3_vercel_database_runtime_config();
        self::assertArrayNotHasKey('driverOptions', $database);

        $this->setEnv('TYPO3_DB_PERSISTENT_CONNECTION', '1');
        $this->setEnv('DATABASE_URL', 'sqlite:////tmp/typo3/camino.sqlite');
        $database = \typo3_vercel_database_runtime_config();
        self::assertArrayNotHasKey('driverOptions', $database);
    }

    public function testScopesRenderedPageCacheToVercelCommit(): void
    {
        $this->setEnv('TYPO3_REDIS_PREFIX', 'camino:');
        $this->setEnv('VERCEL_GIT_COMMIT_SHA', '0123456789abcdef0123456789abcdef01234567');

        $pages = \typo3_vercel_redis_cache_configuration('pages', true, [], true);
        $hash = \typo3_vercel_redis_cache_configuration('hash');

        self::assertSame('camino:pages:deploy-0123456789abcdef0123456789abcdef:', $pages['options']['keyPrefix']);
        self::assertSame('camino:hash:', $hash['options']['keyPrefix']);
    }

    public function testExportsRequestScopedOidcTokenForChildProcesses(): void
    {
        $_SERVER['HTTP_X_VERCEL_OIDC_TOKEN'] = 'request-token';

        self::assertTrue(\typo3_vercel_export_request_oidc_token());
        self::assertSame('request-token', getenv('VERCEL_OIDC_TOKEN'));
    }

    public function testBooleanAndIntegerEnvironmentBounds(): void
    {
        $this->setEnv('TEST_BOOLEAN', 'yes');
        $this->setEnv('TEST_INTEGER', '999');

        self::assertTrue(\typo3_vercel_bool_env('TEST_BOOLEAN', false));
        self::assertSame(20, \typo3_vercel_int_env('TEST_INTEGER', 5, 1, 20));
        self::assertSame(5, \typo3_vercel_int_env('MISSING_INTEGER', 5, 1, 20));
    }

    public function testTruthyAcceptsTheCanonicalTokensOnly(): void
    {
        foreach (['1', 'true', 'YES', 'On', ' 1 '] as $value) {
            self::assertTrue(\typo3_vercel_truthy($value));
        }
        foreach (['', '0', 'false', 'no', 'off', 'enabled', '2'] as $value) {
            self::assertFalse(\typo3_vercel_truthy($value));
        }
    }

    public function testDetectsMissingTableErrorsAcrossDatabaseEngines(): void
    {
        self::assertTrue(\typo3_vercel_is_missing_table_error('SQLSTATE[HY000]: General error: 1 no such table: be_users'));
        self::assertTrue(\typo3_vercel_is_missing_table_error('SQLSTATE[42P01]: relation "be_users" does not exist'));
        self::assertTrue(\typo3_vercel_is_missing_table_error("SQLSTATE[42S02]: Table 'typo3.be_users' doesn't exist"));
        self::assertFalse(\typo3_vercel_is_missing_table_error('SQLSTATE[HY000] [1045] Access denied for user'));
        self::assertFalse(\typo3_vercel_is_missing_table_error('SQLSTATE[HY000] [1049] Unknown database'));
    }

    public function testResolvesTheFirstConfiguredSolrServiceUrlAlias(): void
    {
        self::assertNull(\typo3_vercel_solr_service_url());

        $this->setEnv('SOLR_INTERNAL_URL', 'http://internal.example.test');
        self::assertSame('http://internal.example.test', \typo3_vercel_solr_service_url());

        $this->setEnv('TYPO3_SOLR_SERVICE_URL', 'http://service.example.test');
        self::assertSame('http://service.example.test', \typo3_vercel_solr_service_url());
    }

    public function testInstallToolAllowsOnlyBackendContextOrExplicitOptIn(): void
    {
        self::assertFalse(\typo3_vercel_install_tool_direct_access([]));
        self::assertFalse(\typo3_vercel_install_tool_direct_access(['__typo3_install' => '']));
        self::assertTrue(\typo3_vercel_install_tool_direct_access([
            '__typo3_install' => '',
            'install' => ['context' => 'backend'],
        ]));

        $this->setEnv('TYPO3_INSTALL_TOOL_ENABLED', '1');
        self::assertTrue(\typo3_vercel_install_tool_direct_access(['__typo3_install' => '']));
    }

    public function testInstallToolPasswordHashDoesNotEnableStandaloneAccess(): void
    {
        $this->setEnv('TYPO3_INSTALL_TOOL_PASSWORD_HASH', '$argon2id$example');

        self::assertFalse(\typo3_vercel_install_tool_direct_access(['__typo3_install' => '']));
    }

    public function testInstallToolAllowsOnlySessionBackedFollowUpActions(): void
    {
        $query = [
            '__typo3_install' => '',
            'install' => ['action' => 'labels'],
        ];
        $session = ['Typo3InstallTool' => 'valid-session-id-123456'];

        self::assertFalse(\typo3_vercel_install_tool_direct_access($query));
        self::assertTrue(\typo3_vercel_install_tool_direct_access($query, $session));
        self::assertFalse(\typo3_vercel_install_tool_direct_access(
            ['__typo3_install' => '', 'install' => ['action' => 'init']],
            $session,
        ));
        self::assertFalse(\typo3_vercel_install_tool_direct_access(
            $query,
            ['Typo3InstallTool' => 'invalid session'],
        ));
        self::assertTrue(\typo3_vercel_install_tool_direct_access(
            ['__typo3_install' => ''],
            $session,
            ['install' => ['action' => 'labels']],
        ));
    }

    public function testMapsRedisUrlToInstallToolSessionHandler(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The Redis extension is not installed.');
        }

        $this->setEnv('REDIS_URL', 'rediss://default:p%40ss@redis.example.test:6380/3');

        self::assertSame([
            'className' => 'TYPO3\\CMS\\Install\\Service\\Session\\RedisSessionHandler',
            'options' => [
                'host' => 'tls://redis.example.test',
                'port' => 6380,
                'database' => 3,
                'authentication' => [
                    'user' => 'default',
                    'pass' => 'p@ss',
                ],
            ],
        ], \typo3_vercel_install_tool_session_handler_configuration());
    }

    public function testParsesSystemMaintainerUids(): void
    {
        self::assertSame([1], \typo3_vercel_system_maintainers());

        $this->setEnv('TYPO3_SYSTEM_MAINTAINERS', '7, 2,7');

        self::assertSame([7, 2], \typo3_vercel_system_maintainers());
    }

    public function testRejectsInvalidSystemMaintainerUids(): void
    {
        $this->setEnv('TYPO3_SYSTEM_MAINTAINERS', '1,admin');

        $this->expectException(\RuntimeException::class);
        \typo3_vercel_system_maintainers();
    }

    public function testResolvesActiveAdminUidFromDatabase(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'typo3-maintainer-');
        self::assertIsString($databasePath);

        try {
            $pdo = new \PDO('sqlite:' . $databasePath);
            $pdo->exec('CREATE TABLE be_users (uid INTEGER, username TEXT, admin INTEGER, disable INTEGER, deleted INTEGER)');
            $pdo->exec("INSERT INTO be_users VALUES (1, 'old-admin', 1, 0, 0)");
            $pdo->exec("INSERT INTO be_users VALUES (7, 'admin', 1, 0, 0)");
            $pdo->exec("INSERT INTO be_users VALUES (8, 'admin', 1, 1, 0)");

            self::assertSame(
                [7],
                \typo3_vercel_resolve_system_maintainers(
                    ['driver' => 'pdo_sqlite', 'path' => $databasePath],
                    'admin',
                ),
            );
        } finally {
            @unlink($databasePath);
        }
    }

    /** @return list<string> */
    private function environmentNames(): array
    {
        return [
            'DATABASE_URL',
            'POSTGRES_URL',
            'MYSQL_URL',
            'TYPO3_DB_DRIVER',
            'REDIS_URL',
            'TYPO3_REDIS_URL',
            'TYPO3_REDIS_PREFIX',
            'TYPO3_REDIS_PERSISTENT_CONNECTION',
            'TYPO3_REDIS_READ_TIMEOUT',
            'TYPO3_REDIS_SESSIONS',
            'TYPO3_CACHE_BACKEND',
            'TYPO3_DB_PERSISTENT_CONNECTION',
            'VERCEL_GIT_COMMIT_SHA',
            'VERCEL_OIDC_TOKEN',
            'HTTP_X_VERCEL_OIDC_TOKEN',
            'X_VERCEL_OIDC_TOKEN',
            'TEST_BOOLEAN',
            'TEST_INTEGER',
            'MISSING_INTEGER',
            'TYPO3_SOLR_SERVICE_URL',
            'SOLR_SERVICE_URL',
            'TYPO3_SOLR_INTERNAL_URL',
            'SOLR_INTERNAL_URL',
            'TYPO3_SYSTEM_MAINTAINERS',
            'TYPO3_INSTALL_TOOL_ENABLED',
            'TYPO3_INSTALL_TOOL_PASSWORD_HASH',
        ];
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
