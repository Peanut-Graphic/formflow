<?php
/**
 * WifiGateReportingTest — the number that justifies the feature.
 *
 * The gate's whole business case is "we stopped N failed installs and saved M
 * of those customers into the switch program." Storing the data without
 * surfacing it means nobody can ever answer that, and the next time the client
 * asks whether it was worth building, the honest answer is "we don't know."
 *
 * @package FormFlow
 */

namespace ISF\Tests\Unit\Frontend;

use ISF\Tests\Unit\TestCase;
use ISF\ReportGenerator;
use ReflectionMethod;

final class WifiGateReportingTest extends TestCase
{
    /**
     * Build a summary over a fixed set of completed submissions.
     */
    private function summarise(array $submissions): array
    {
        $db = $this->mockDatabase();
        $db->shouldReceive('get_submissions')->andReturn($submissions);

        $generator = new ReportGenerator($db);
        $method = new ReflectionMethod($generator, 'generate_submissions_summary');

        return $method->invoke($generator, '2026-07-01', '2026-07-31', null);
    }

    private function submission(array $overrides = []): array
    {
        return array_merge([
            'device_type'      => 'thermostat',
            'has_wifi'         => 'yes',
            'device_converted' => 0,
            'completed_at'     => '2026-07-15 10:00:00',
            'created_at'       => '2026-07-15 09:00:00',
        ], $overrides);
    }

    public function test_summary_counts_customers_who_were_asked_about_wifi(): void
    {
        $summary = $this->summarise([
            $this->submission(),
            $this->submission(['has_wifi' => 'no', 'device_type' => 'dcu', 'device_converted' => 1]),
            // Never asked: chose the switch outright, or an ungated utility.
            $this->submission(['has_wifi' => null, 'device_type' => 'dcu']),
        ]);

        $this->assertArrayHasKey('wifi', $summary, 'The report carries no WiFi figures at all.');
        $this->assertSame(2, $summary['wifi']['asked'], 'A NULL answer means "never asked" and must not be counted.');
    }

    public function test_summary_counts_the_customers_the_gate_caught(): void
    {
        $summary = $this->summarise([
            $this->submission(),
            $this->submission(['has_wifi' => 'no', 'device_type' => 'dcu', 'device_converted' => 1]),
            $this->submission(['has_wifi' => 'no', 'device_type' => 'dcu', 'device_converted' => 1]),
        ]);

        $this->assertSame(2, $summary['wifi']['no_wifi'], 'Cannot tell how many failed installs were prevented.');
    }

    /**
     * The one that matters most: of the people told they were ineligible, how
     * many stayed rather than leaving.
     */
    public function test_summary_counts_gate_driven_conversions_separately(): void
    {
        $summary = $this->summarise([
            $this->submission(['has_wifi' => 'no', 'device_type' => 'dcu', 'device_converted' => 1]),
            // Chose the switch outright. Not a conversion, and counting it as
            // one would inflate the feature's apparent value.
            $this->submission(['has_wifi' => null, 'device_type' => 'dcu', 'device_converted' => 0]),
        ]);

        $this->assertSame(
            1,
            $summary['wifi']['converted'],
            'A switch-first enrollment is being miscounted as a gate conversion.'
        );
    }

    public function test_existing_device_breakdown_is_unchanged(): void
    {
        $summary = $this->summarise([
            $this->submission(),
            $this->submission(['device_type' => 'dcu']),
        ]);

        $this->assertSame(1, $summary['by_device']['thermostat']);
        $this->assertSame(1, $summary['by_device']['dcu']);
        $this->assertSame(2, $summary['total_completed']);
    }

    /**
     * Pre-4.1.0 rows have neither column. The report must degrade to zeroes
     * rather than warning or crashing.
     */
    public function test_pre_feature_rows_report_as_zero_without_warnings(): void
    {
        $summary = $this->summarise([
            ['device_type' => 'thermostat', 'completed_at' => '2026-06-01 10:00:00', 'created_at' => '2026-06-01 09:00:00'],
        ]);

        $this->assertSame(0, $summary['wifi']['asked']);
        $this->assertSame(0, $summary['wifi']['no_wifi']);
        $this->assertSame(0, $summary['wifi']['converted']);
    }
}
