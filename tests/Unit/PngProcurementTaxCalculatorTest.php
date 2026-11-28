<?php

namespace Tests\Unit;

use App\Services\PngProcurementTaxCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PngProcurementTaxCalculatorTest extends TestCase
{
    public function test_domestic_standard_zero_rated_and_exempt_treatments_are_distinct(): void
    {
        $calculator = new PngProcurementTaxCalculator();

        self::assertSame(10.0, $calculator->domesticSupply(100, 'standard')['gst_amount']);
        self::assertSame(0.0, $calculator->domesticSupply(100, 'zero_rated')['gst_amount']);
        self::assertSame('exempt', $calculator->domesticSupply(100, 'exempt')['treatment']);
        self::assertSame(0.0, $calculator->domesticSupply(100, 'standard', false)['gst_amount']);
    }

    public function test_import_gst_base_includes_customs_duty_excise_and_unincluded_freight(): void
    {
        $result = (new PngProcurementTaxCalculator())->importAssessment(1000, 5, 20, 30);

        self::assertSame(50.0, $result['customs_duty']);
        self::assertSame(1100.0, $result['gst_base']);
        self::assertSame(110.0, $result['import_gst']);
        self::assertSame(180.0, $result['total_border_taxes']);
    }

    public function test_import_exemption_zeroes_import_gst_but_not_customs_duty(): void
    {
        $result = (new PngProcurementTaxCalculator())->importAssessment(1000, 5, 0, 0, true);

        self::assertSame(50.0, $result['customs_duty']);
        self::assertSame(0.0, $result['import_gst']);
    }

    public function test_invalid_tariff_rate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PngProcurementTaxCalculator())->importAssessment(1000, 150);
    }
}
