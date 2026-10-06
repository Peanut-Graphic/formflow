<?php
/**
 * SFTP destination against the phpseclib 4 API.
 *
 * phpseclib 4 changed the calls this destination makes: namespace
 * phpseclib3 -> phpseclib4, put()/nlist() throw FileSystemException instead
 * of returning false (put() now returns void), getLastError() was removed,
 * getServerPublicHostKey() returns ?string, and PublicKeyLoader::load()
 * takes a ?string passphrase. The network is mocked: create_client() is
 * overridden to hand back a Mockery double of phpseclib4\Net\SFTP.
 */

namespace ISF\Tests\Unit\Destinations;

use ISF\Destinations\DeliveryFailure;
use ISF\Destinations\Sftp\SftpDestination;
use ISF\Tests\Unit\TestCase;
use Mockery;
use phpseclib4\Crypt\Common\PrivateKey;
use phpseclib4\Crypt\EC;
use phpseclib4\Exception\FileSystemException;
use phpseclib4\Net\SFTP;
use phpseclib4\Net\SFTP\StatusCode;

require_once ISF_PLUGIN_DIR . 'includes/destinations/interface-destination.php';
require_once ISF_PLUGIN_DIR . 'includes/destinations/class-delivery-result.php';
require_once ISF_PLUGIN_DIR . 'includes/destinations/class-base-destination.php';
require_once ISF_PLUGIN_DIR . 'connectors/sftp/class-sftp-formatter.php';
require_once ISF_PLUGIN_DIR . 'connectors/sftp/class-sftp-destination.php';

class SftpDestinationTest extends TestCase
{
    /** @var array<int, array{0: string, 1: int}> */
    private array $clientArgs = [];

    private function destinationWith(SFTP $client): SftpDestination
    {
        $args = &$this->clientArgs;
        return new class ($client, $args) extends SftpDestination {
            private SFTP $client;
            private array $args;

            public function __construct(SFTP $client, array &$args)
            {
                $this->client = $client;
                $this->args = &$args;
            }

            protected function create_client(string $host, int $port): SFTP
            {
                $this->args[] = [$host, $port];
                return $this->client;
            }
        };
    }

    /** @return \Mockery\MockInterface&SFTP */
    private function mockClient()
    {
        $sftp = Mockery::mock(SFTP::class);
        // SSH2::__destruct() calls disconnect(); allow it beyond the asserted calls.
        $sftp->shouldReceive('disconnect')->byDefault();
        return $sftp;
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'host' => 'sftp.example.test',
            'port' => 2222,
            'username' => 'intake',
            'auth_mode' => 'password',
            'password' => 's3cret',
            'remote_path' => '/incoming/',
            'filename_template' => 'export_{submission_id}.{ext}',
            'format' => 'json',
        ], $overrides);
    }

    private function submission(): array
    {
        return ['submission_id' => 42, 'first_name' => 'Ada'];
    }

    public function test_deliver_succeeds_when_put_returns_void(): void
    {
        $sftp = $this->mockClient();
        $sftp->shouldReceive('login')->once()->with('intake', 's3cret')->andReturn(true);
        $captured = null;
        $sftp->shouldReceive('put')->once()
            ->with('/incoming/export_42.json', Mockery::on(function ($bytes) use (&$captured) {
                $captured = $bytes;
                return is_string($bytes) && $bytes !== '';
            }));
        $sftp->shouldReceive('disconnect')->once();

        $result = $this->destinationWith($sftp)->deliver($this->submission(), $this->config());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('/incoming/export_42.json', $result->meta['remote_path']);
        $this->assertSame(hash('sha256', $captured), $result->payload_hash);
        $this->assertSame([['sftp.example.test', 2222]], $this->clientArgs);
    }

    public function test_deliver_maps_put_transport_failure_to_transient(): void
    {
        $sftp = $this->mockClient();
        $sftp->shouldReceive('login')->andReturn(true);
        $sftp->shouldReceive('put')->once()
            ->andThrow(new FileSystemException('Error from server (SSH_FX_FAILURE)', StatusCode::FAILURE));
        $sftp->shouldReceive('disconnect')->once();

        $result = $this->destinationWith($sftp)->deliver($this->submission(), $this->config());

        $this->assertFalse($result->success);
        $this->assertSame(DeliveryFailure::TRANSIENT, $result->failure_kind);
        $this->assertStringContainsString('SSH_FX_FAILURE', $result->message);
        $this->assertSame('/incoming/export_42.json', $result->meta['remote_path']);
    }

    public function test_deliver_maps_missing_remote_dir_to_config_failure(): void
    {
        $sftp = $this->mockClient();
        $sftp->shouldReceive('login')->andReturn(true);
        $sftp->shouldReceive('put')->once()
            ->andThrow(new FileSystemException('Error from server (NO_SUCH_FILE)', StatusCode::NO_SUCH_FILE));
        $sftp->shouldReceive('disconnect')->once();

        $result = $this->destinationWith($sftp)->deliver($this->submission(), $this->config());

        $this->assertFalse($result->success);
        $this->assertSame(DeliveryFailure::CONFIG, $result->failure_kind);
    }

    public function test_login_failure_reads_get_errors_and_is_auth_failure(): void
    {
        $sftp = $this->mockClient();
        $sftp->shouldReceive('login')->once()->andReturn(false);
        $sftp->shouldReceive('getErrors')->once()->andReturn([]);
        $sftp->shouldNotReceive('put');

        $result = $this->destinationWith($sftp)->deliver($this->submission(), $this->config());

        $this->assertFalse($result->success);
        $this->assertSame(DeliveryFailure::AUTH, $result->failure_kind);
        $this->assertSame('auth_failed', $result->meta['code']);
        $this->assertStringContainsString('SFTP login failed.', $result->message);
    }

    public function test_key_auth_passes_a_private_key_object_to_login(): void
    {
        $key = EC::createKey('Ed25519');
        $sftp = $this->mockClient();
        $sftp->shouldReceive('login')->once()
            ->with('intake', Mockery::type(PrivateKey::class))
            ->andReturn(true);
        $sftp->shouldReceive('put')->once();

        $result = $this->destinationWith($sftp)->deliver($this->submission(), $this->config([
            'auth_mode' => 'key',
            'password' => '',
            'private_key' => $key->toString('OpenSSH'),
        ]));

        $this->assertTrue($result->success, $result->message);
    }

    public function test_key_auth_with_passphrase_decrypts_the_key(): void
    {
        $key = EC::createKey('Ed25519')->withPassword('hunter2');
        $sftp = $this->mockClient();
        $sftp->shouldReceive('login')->once()
            ->with('intake', Mockery::type(PrivateKey::class))
            ->andReturn(true);
        $sftp->shouldReceive('put')->once();

        $result = $this->destinationWith($sftp)->deliver($this->submission(), $this->config([
            'auth_mode' => 'key',
            'private_key' => $key->toString('OpenSSH'),
            'private_key_passphrase' => 'hunter2',
        ]));

        $this->assertTrue($result->success, $result->message);
    }

    public function test_unparseable_key_never_reaches_login(): void
    {
        $sftp = $this->mockClient();
        $sftp->shouldNotReceive('login');

        $result = $this->destinationWith($sftp)->test_connection($this->config([
            'auth_mode' => 'key',
            'private_key' => 'not a key',
        ]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Private key could not be parsed.', $result['message']);
    }

    public function test_host_key_fingerprint_match_allows_login(): void
    {
        $hostKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleHostKey';
        $fp = 'sha256:' . base64_encode(hash('sha256', $hostKey, true));
        $sftp = $this->mockClient();
        $sftp->shouldReceive('getServerPublicHostKey')->once()->andReturn($hostKey);
        $sftp->shouldReceive('login')->once()->andReturn(true);
        $sftp->shouldReceive('nlist')->once()->with('/incoming/')->andReturn(['.', '..', 'a.csv']);

        $result = $this->destinationWith($sftp)->test_connection($this->config(['host_key_fingerprint' => $fp]));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('ok', $result['code']);
        $this->assertStringContainsString('3 entries', $result['message']);
    }

    public function test_host_key_fingerprint_mismatch_refuses_before_login(): void
    {
        $sftp = $this->mockClient();
        $sftp->shouldReceive('getServerPublicHostKey')->once()->andReturn('ssh-ed25519 AAAAOther');
        $sftp->shouldNotReceive('login');

        $result = $this->destinationWith($sftp)->test_connection($this->config(['host_key_fingerprint' => 'sha256:nope']));

        $this->assertFalse($result['success']);
        $this->assertSame('host_key_mismatch', $result['code']);
    }

    public function test_unverifiable_host_key_null_is_refused(): void
    {
        $sftp = $this->mockClient();
        $sftp->shouldReceive('getServerPublicHostKey')->once()->andReturn(null);
        $sftp->shouldNotReceive('login');

        $result = $this->destinationWith($sftp)->test_connection($this->config(['host_key_fingerprint' => 'sha256:any']));

        $this->assertFalse($result['success']);
        $this->assertSame('host_key_mismatch', $result['code']);
    }

    public function test_connection_reports_unlistable_path_when_nlist_throws(): void
    {
        $sftp = $this->mockClient();
        $sftp->shouldReceive('login')->andReturn(true);
        $sftp->shouldReceive('nlist')->once()
            ->andThrow(new FileSystemException('Error from server (NO_SUCH_FILE)', StatusCode::NO_SUCH_FILE));
        $sftp->shouldReceive('disconnect')->once();

        $result = $this->destinationWith($sftp)->test_connection($this->config());

        $this->assertFalse($result['success']);
        $this->assertSame('path_not_found', $result['code']);
    }

    public function test_real_client_is_constructed_lazily_without_connecting(): void
    {
        $destination = new SftpDestination();
        $method = new \ReflectionMethod($destination, 'create_client');

        // Port 1 on localhost: a connect attempt here would throw.
        $client = $method->invoke($destination, '127.0.0.1', 1);

        $this->assertInstanceOf(SFTP::class, $client);
        $this->assertFalse($client->isConnected());
    }
}
