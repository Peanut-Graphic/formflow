<?php
namespace ISF\Tests\Regression;
use Brain\Monkey;
use Brain\Monkey\Functions;
use ISF\Connectors\DominionPtr\HubProspectClient;
use PHPUnit\Framework\TestCase;
require_once ISF_PLUGIN_DIR.'connectors/dominion-ptr/class-hub-prospect-client.php';
final class HubProspectClientTest extends TestCase {
 protected function setUp(): void {parent::setUp();Monkey\setUp();Functions\when('__')->returnArg(1);}
 protected function tearDown(): void {Monkey\tearDown();parent::tearDown();}
 public function test_plain_http_cannot_receive_site_credentials(): void {$this->expectException(\InvalidArgumentException::class);new HubProspectClient('http://hub.example.com','secret');}
 public function test_connection_url_cannot_embed_credentials_or_query(): void {$this->expectException(\InvalidArgumentException::class);new HubProspectClient('https://user:password@hub.example.com/?secret=1','secret');}
 public function test_credentials_are_not_forwarded_to_redirect_targets(): void {
   Functions\when('wp_parse_url')->alias('parse_url');Functions\when('wp_json_encode')->alias('json_encode');Functions\when('is_wp_error')->justReturn(false);
   // Public numeric IP makes the SSRF check deterministic without DNS.
   Functions\expect('wp_remote_request')->once()->with('https://8.8.8.8/api/v1/forms/dominion/prospect-lookup',\Mockery::on(function($args){return $args['redirection']===0&&$args['sslverify']===true&&$args['headers']['Authorization']==='Bearer server-key';}))->andReturn([]);
   Functions\when('wp_remote_retrieve_response_code')->justReturn(302);
   $this->assertSame(['matched'=>false],(new HubProspectClient('https://8.8.8.8','server-key'))->lookup('dominion','01234',null,'person@example.com'));
 }
}
