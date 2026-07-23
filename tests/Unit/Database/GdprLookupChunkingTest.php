<?php
/**
 * Guards the GDPR lookup against loading the whole submissions table at once.
 *
 * find_submissions_for_gdpr() must decrypt-and-match in PHP (the body is
 * encrypted, so SQL can't filter it), but it now pages through the table in
 * bounded LIMIT/OFFSET chunks instead of one unbounded SELECT — otherwise a
 * large table OOMs / times out. This proves a match on a LATER page is still
 * found (i.e. it doesn't stop after the first chunk), and covers the extracted
 * match helper.
 */

namespace ISF\Tests\Unit\Database;

use ISF\Tests\Unit\TestCase;
use ISF\Database\Database;
use ISF\Encryption;

final class GdprLookupChunkingTest extends TestCase
{
    private function fakeEncryption(Database $db): void
    {
        $enc = \Mockery::mock(Encryption::class);
        $enc->shouldReceive('decrypt_array')->andReturnUsing(
            static fn ($s) => is_string($s) && $s !== '' ? (json_decode($s, true) ?: []) : []
        );
        $ref = new \ReflectionProperty(Database::class, 'encryption');
        $ref->setAccessible(true);
        $ref->setValue($db, $enc);
    }

    public function test_finds_a_match_on_a_later_page(): void
    {
        // Page 1: a full 500-row chunk, none matching. Page 2: the target.
        // Page 3: empty. A one-shot SELECT that stopped after page 1 would miss
        // the target; the chunked loop must keep going while a full page comes
        // back.
        $page1 = array_fill(0, 500, ['form_data' => json_encode(['email' => 'noone@example.com']), 'api_response' => '']);
        $page2 = [['form_data' => json_encode(['email' => 'target@example.com']), 'api_response' => '']];

        $calls = 0;
        $this->mockWpdb([
            'get_results' => function () use (&$calls, $page1, $page2) {
                $calls++;
                if ($calls === 1) {
                    return $page1;
                }
                if ($calls === 2) {
                    return $page2;
                }
                return [];
            },
        ]);

        $db = new Database();
        $this->fakeEncryption($db);

        $found = $db->find_submissions_for_gdpr('target@example.com');

        $this->assertGreaterThanOrEqual(2, $calls, 'must request more than one page');
        $this->assertCount(1, $found, 'the match on page 2 must be found');
        $this->assertSame('target@example.com', $found[0]['form_data']['email']);
    }

    public function test_stops_after_a_partial_page(): void
    {
        // A single short page (< chunk) means the table is drained: exactly one
        // query, no needless extra round-trip.
        $calls = 0;
        $this->mockWpdb([
            'get_results' => function () use (&$calls) {
                $calls++;
                return $calls === 1
                    ? [['form_data' => json_encode(['email' => 'a@example.com']), 'api_response' => '']]
                    : [];
            },
        ]);

        $db = new Database();
        $this->fakeEncryption($db);

        $db->find_submissions_for_gdpr('nobody@example.com');
        $this->assertSame(1, $calls, 'a partial first page must end the scan immediately');
    }

    public function test_subject_match_helper(): void
    {
        $db = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($db, 'gdpr_subject_matches');
        $m->setAccessible(true);
        $keys = ['account_number', 'utility_no', 'account', 'ca_no', 'comverge_no'];

        $this->assertTrue($m->invoke($db, ['email' => 'A@Ex.com'], 'a@ex.com', null, $keys), 'email match, case-insensitive');
        $this->assertTrue($m->invoke($db, ['utility_no' => '2100'], '', '2100', $keys), 'account under utility_no');
        $this->assertFalse($m->invoke($db, ['email' => 'a@ex.com'], 'z@ex.com', 'ZZ', $keys), 'no match');
    }
}
