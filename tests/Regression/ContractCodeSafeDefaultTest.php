<?php
/**
 * Regression guard: getContractCode() must never silently pick 100% cycling.
 *
 * The IntelliSource contract code encodes how aggressively a customer's
 * equipment is curtailed. getContractCode() used to return '09' (100%-Pro-VHF,
 * MAXIMUM cycling) for any level/device combination not in its table — so a
 * typo'd or unexpected cycling level silently enrolled the customer at full
 * curtailment. It now throws on an unmapped combination so the bad input is
 * rejected instead of shipping a wrong, maximally-aggressive contract.
 *
 * getContractCode() is pure static PHP, so this needs no WordPress.
 */

namespace ISF\Tests\Regression;

use PHPUnit\Framework\TestCase;
use ISF\Api\FieldMapper;
use ISF\Api\FieldMappingException;

final class ContractCodeSafeDefaultTest extends TestCase
{
    /**
     * @dataProvider validCombos
     */
    public function test_valid_combinations_map_to_their_code(string $level, string $device, string $expected): void
    {
        $this->assertSame($expected, FieldMapper::getContractCode($level, $device));
    }

    public static function validCombos(): array
    {
        return [
            '50 thermostat'  => ['50', 'thermostat', '01'],
            '75 thermostat'  => ['75', 'thermostat', '05'],
            '100 thermostat' => ['100', 'thermostat', '09'],
            '50 dcu'         => ['50', 'dcu', '04'],
            '100 dcu'        => ['100', 'dcu', '12'],
        ];
    }

    /**
     * @dataProvider unmappedCombos
     */
    public function test_unmapped_combination_throws_rather_than_defaulting_to_full_cycling(string $level, string $device): void
    {
        $this->expectException(FieldMappingException::class);
        FieldMapper::getContractCode($level, $device);
    }

    public static function unmappedCombos(): array
    {
        return [
            'typo level'        => ['30', 'thermostat'],
            'garbage level'     => ['999', 'thermostat'],
            'empty level'       => ['', 'thermostat'],
            'unmapped dcu level'=> ['30', 'dcu'],
        ];
    }
}
