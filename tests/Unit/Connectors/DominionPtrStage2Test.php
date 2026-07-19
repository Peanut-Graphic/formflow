<?php
/**
 * Stage 2 scaffolding: identity verification (prospect_verifications) and the
 * portal hand-off (create_from_prospect).
 *
 * Pure tests — HTTP is faked by overriding the protected http_post_json /
 * http_get_json, so no live calls occur. Request shapes are asserted against
 * the reverse-engineered IntelliSource contract in
 * peanut-meta/dominion-ptr-intellisource-api-contract.md.
 *
 * NOTE ON CONFIDENCE: these pin what we believe the contract to be, read from
 * Itron's own SPA client. They are NOT proof the API accepts these payloads —
 * no write endpoint has ever been called. The enroll write stays unimplemented
 * until Itron confirms byot-vs-full, whether verification is mandatory, and the
 * IP allowlist. Treat a green suite here as "we built what we read", not
 * "Dominion enrollment works".
 *
 * @package FormFlow\Tests\Unit
 */

namespace ISF\Tests\Unit\Connectors;

use Brain\Monkey\Functions;
use ISF\Connectors\DominionPtr\DominionPtrConnector;
use ISF\Tests\Unit\TestCase;

require_once ISF_PLUGIN_DIR . 'includes/api/interface-api-connector.php';
require_once ISF_PLUGIN_DIR . 'includes/api/class-scheduling-result.php';
require_once ISF_PLUGIN_DIR . 'connectors/powerportal-json/class-powerportal-json-connector.php';
require_once ISF_PLUGIN_DIR . 'connectors/dominion-ptr/class-dominion-ptr-connector.php';

final class DominionPtrStage2Test extends TestCase
{
    private const CFG = ['api_endpoint' => 'https://www.dominionenergyptr.com/ptr/residential/api'];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg(1);
    }

    /**
     * Connector that records the last POST/GET and returns queued fixtures.
     */
    private function recordingConnector(array $postResp = [], array $getResp = []): DominionPtrConnector
    {
        return new class($postResp, $getResp) extends DominionPtrConnector
        {
            public array $lastPost = [];

            public array $lastGet = [];

            private array $postResp;

            private array $getResp;

            public function __construct(array $postResp, array $getResp)
            {
                $this->postResp = $postResp;
                $this->getResp = $getResp;
            }

            protected function http_post_json(string $url, array $data): array
            {
                $this->lastPost = ['url' => $url, 'data' => $data];
                if (isset($this->postResp['__throw'])) {
                    throw new \Exception('boom');
                }

                return $this->postResp;
            }

            protected function http_get_json(string $url, array $query = []): array
            {
                $this->lastGet = ['url' => $url, 'query' => $query];
                if (isset($this->getResp['__throw'])) {
                    throw new \Exception('boom');
                }

                return $this->getResp;
            }
        };
    }

    // --- send_verification -------------------------------------------------

    public function test_send_verification_posts_expected_payload_and_parses_id(): void
    {
        $c = $this->recordingConnector(['id' => 4021]);
        $r = $c->send_verification(
            ['email' => 'x@gmail.com', 'mobile_telephone' => '8045551212', 'first_name' => 'ASHOK', 'last_name' => 'RAMASUBBU', 'method' => 'email'],
            self::CFG
        );

        $this->assertTrue($r['sent']);
        $this->assertSame(4021, $r['verification_id']);
        $this->assertStringEndsWith('/prospect_verifications', $c->lastPost['url']);
        $this->assertSame('email', $c->lastPost['data']['method']);
        $this->assertSame('json', $c->lastPost['data']['preferred_format']);
        $this->assertSame('x@gmail.com', $c->lastPost['data']['email']);
        $this->assertSame('ASHOK', $c->lastPost['data']['first_name']);
    }

    public function test_send_verification_failure_is_non_fatal(): void
    {
        $c = $this->recordingConnector(['__throw' => true]);
        $r = $c->send_verification(['email' => 'x@gmail.com'], self::CFG);

        $this->assertFalse($r['sent']);
        $this->assertNull($r['verification_id']);
    }

    // --- check_verification ------------------------------------------------

    public function test_check_verification_hits_correct_url_and_parses_verified(): void
    {
        $c = $this->recordingConnector([], ['verified' => true]);
        $r = $c->check_verification('4021', '123456', self::CFG);

        $this->assertTrue($r['verified']);
        $this->assertStringEndsWith('/prospect_verifications/4021', $c->lastGet['url']);
        $this->assertSame('123456', $c->lastGet['query']['verification_code']);
    }

    public function test_check_verification_negative_when_no_pass_flag(): void
    {
        // Fails closed. The exact "passed" field is INFERRED, not confirmed —
        // an unrecognised response must never read as verified.
        $c = $this->recordingConnector([], ['status' => 'pending']);
        $r = $c->check_verification('4021', '000000', self::CFG);

        $this->assertFalse($r['verified']);
    }

    public function test_check_verification_id_is_url_encoded(): void
    {
        // The id comes back from their API; don't let it break out of the path.
        $c = $this->recordingConnector([], ['verified' => true]);
        $c->check_verification('40/21', '123456', self::CFG);

        $this->assertStringEndsWith('/prospect_verifications/40%2F21', $c->lastGet['url']);
    }

    // --- create_portal_handoff (the fully-confirmed one) -------------------

    public function test_create_portal_handoff_parses_token_and_id_and_posts_mapped_fields(): void
    {
        $c = $this->recordingConnector(['portal_user' => ['id' => 5150, 'enrollment_token' => 'tok-abc123']]);
        $r = $c->create_portal_handoff(
            ['premise_id' => 728, 'zip' => '23116', 'utility_no' => '210010506231', 'email' => 'x@gmail.com', 'first_name' => 'ASHOK', 'last_name' => 'RAMASUBBU', 'mobile_telephone' => '8045551212'],
            self::CFG
        );

        $this->assertTrue($r['success']);
        $this->assertSame(5150, $r['portal_user_id']);
        $this->assertSame('tok-abc123', $r['enrollment_token']);
        $this->assertStringEndsWith('/portal_user/create_from_prospect', $c->lastPost['url']);
        $this->assertSame(728, $c->lastPost['data']['premise_id']);
        $this->assertSame('210010506231', $c->lastPost['data']['utility_no']);
        $this->assertSame('json', $c->lastPost['data']['preferred_format']);
    }

    public function test_create_portal_handoff_normalises_zip(): void
    {
        $c = $this->recordingConnector(['portal_user' => ['id' => 1, 'enrollment_token' => 't']]);
        $c->create_portal_handoff(['premise_id' => 1, 'zip' => '23116-4021', 'utility_no' => 'x', 'email' => 'a@b.com'], self::CFG);

        $this->assertSame('231164021', $c->lastPost['data']['zip']);
    }

    public function test_create_portal_handoff_missing_portal_user_is_failure(): void
    {
        $c = $this->recordingConnector(['error' => 'nope']);
        $r = $c->create_portal_handoff(['premise_id' => 1, 'utility_no' => 'x', 'email' => 'x@gmail.com'], self::CFG);

        $this->assertFalse($r['success']);
        $this->assertNull($r['enrollment_token']);
    }

    public function test_create_portal_handoff_partial_portal_user_is_failure(): void
    {
        // id without a token is unusable for the hand-off — must not read as success.
        $c = $this->recordingConnector(['portal_user' => ['id' => 5150]]);
        $r = $c->create_portal_handoff(['premise_id' => 1, 'utility_no' => 'x', 'email' => 'x@gmail.com'], self::CFG);

        $this->assertFalse($r['success']);
        $this->assertNull($r['enrollment_token']);
    }
}
