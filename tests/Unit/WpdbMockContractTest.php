<?php

namespace ISF\Tests\Unit;

class WpdbMockContractTest extends TestCase
{
    public function test_insert_id_configuration_sets_the_wpdb_property(): void
    {
        $wpdb = $this->mockWpdb([
            'insert' => true,
            'insert_id' => 42,
        ]);

        $this->assertTrue($wpdb->insert('wp_test', ['name' => 'Example']));
        $this->assertSame(42, $wpdb->insert_id);
    }

    public function test_explicit_method_expectation_overrides_helper_default(): void
    {
        $wpdb = $this->mockWpdb(['get_var' => 'default']);
        $wpdb->shouldReceive('get_var')->once()->andReturn('explicit');

        $this->assertSame('explicit', $wpdb->get_var('SELECT 1'));
    }

    public function test_explicit_prepare_expectation_overrides_helper_default(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('prepare')->once()->andReturn('prepared override');

        $this->assertSame('prepared override', $wpdb->prepare('SELECT %d', 1));
    }
}
