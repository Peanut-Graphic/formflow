<?php
/**
 * Guards find_submissions_for_gdpr() against missing records stored under a
 * non-default account key.
 *
 * The lookup matched an account number only against form_data['account_number'].
 * Dominion PTR stores it as utility_no, Comverge as ca_no/comverge_no — so a
 * GDPR erase/export by account silently missed everyone enrolled through those
 * paths. It now checks every known account key.
 */

namespace ISF\Tests\Unit\Database;

use ISF\Tests\Unit\TestCase;
use ISF\Database\Database;
use ISF\Encryption;

final class GdprAccountKeyLookupTest extends TestCase
{
    /**
     * Build a Database whose $wpdb returns $rows and whose encryption decodes
     * the JSON we stashed in each row's form_data.
     *
     * @param array<int,array<string,mixed>> $forms One decoded form_data per row.
     */
    private function dbReturning(array $forms): Database
    {
        $rows = array_map(
            static fn ($f) => ['form_data' => json_encode($f), 'api_response' => ''],
            $forms
        );

        $this->mockWpdb(['get_results' => $rows]);

        $db = new Database();

        $enc = \Mockery::mock(Encryption::class);
        $enc->shouldReceive('decrypt_array')->andReturnUsing(
            static fn ($s) => is_string($s) && $s !== '' ? (json_decode($s, true) ?: []) : []
        );
        $ref = new \ReflectionProperty(Database::class, 'encryption');
        $ref->setAccessible(true);
        $ref->setValue($db, $enc);

        return $db;
    }

    public function test_finds_dominion_record_stored_under_utility_no(): void
    {
        $db = $this->dbReturning([
            ['utility_no' => '210010506231', 'email' => 'dom@example.com'],
            ['account_number' => 'OTHER', 'email' => 'other@example.com'],
        ]);

        $found = $db->find_submissions_for_gdpr('nomatch@example.com', '210010506231');

        $this->assertCount(1, $found, 'The utility_no record must be found by account number.');
        $this->assertSame('210010506231', $found[0]['form_data']['utility_no']);
    }

    public function test_finds_comverge_record_stored_under_ca_no(): void
    {
        $db = $this->dbReturning([
            ['ca_no' => 'X12345', 'email' => 'cv@example.com'],
        ]);

        $this->assertCount(1, $db->find_submissions_for_gdpr('nomatch@example.com', 'X12345'));
    }

    public function test_still_matches_email_case_insensitively(): void
    {
        $db = $this->dbReturning([
            ['utility_no' => 'A1', 'email' => 'Person@Example.com'],
            ['utility_no' => 'A2', 'email' => 'someone@else.com'],
        ]);

        $found = $db->find_submissions_for_gdpr('person@example.com');

        $this->assertCount(1, $found);
        $this->assertSame('A1', $found[0]['form_data']['utility_no']);
    }

    public function test_no_false_positive_when_nothing_matches(): void
    {
        $db = $this->dbReturning([
            ['utility_no' => 'A1', 'email' => 'a@example.com'],
        ]);

        $this->assertCount(0, $db->find_submissions_for_gdpr('nobody@example.com', 'ZZZ'));
    }
}
