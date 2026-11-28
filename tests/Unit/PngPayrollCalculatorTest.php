<?php

namespace Tests\Unit;

use App\Services\PngPayrollCalculator;
use PHPUnit\Framework\TestCase;

final class PngPayrollCalculatorTest extends TestCase
{
    public function test_resident_fortnightly_bands_are_progressive(): void
    {
        $calculator = new PngPayrollCalculator();
        self::assertSame(0.0, $calculator->salaryAndWagesTax(770, 'resident', true, 0, 14));
        self::assertSame(150.0, $calculator->salaryAndWagesTax(1270, 'resident', true, 0, 14));
        self::assertSame(230.5, $calculator->salaryAndWagesTax(1500, 'resident', true, 0, 14));
    }

    public function test_non_resident_first_band_is_taxed_at_twenty_two_percent(): void
    {
        $calculator = new PngPayrollCalculator();
        self::assertEqualsWithDelta(169.40, $calculator->salaryAndWagesTax(770, 'non_resident', true, 0, 14), 0.001);
    }

    public function test_dependant_credit_is_not_applied_to_non_residents(): void
    {
        $calculator = new PngPayrollCalculator();
        self::assertEqualsWithDelta(319.40, $calculator->salaryAndWagesTax(1270, 'non_resident', true, 260, 14), 0.001);
    }

    public function test_missing_employment_declaration_uses_maximum_rate(): void
    {
        $calculator = new PngPayrollCalculator();
        self::assertSame(420.0, $calculator->salaryAndWagesTax(1000, 'resident', false, 0, 14));
    }

    public function test_approved_dependant_credit_is_applied_per_fortnight(): void
    {
        $calculator = new PngPayrollCalculator();
        self::assertSame(220.5, $calculator->salaryAndWagesTax(1500, 'resident', true, 260, 14));
    }

    public function test_weekly_period_is_prorated_from_fortnightly_table(): void
    {
        $calculator = new PngPayrollCalculator();
        self::assertSame(34.5, $calculator->salaryAndWagesTax(500, 'resident', true, 0, 7));
    }

    public function test_superannuation_requires_service_and_mandatory_or_dual_voluntary_election(): void
    {
        $calculator = new PngPayrollCalculator();
        self::assertSame(['eligible' => true, 'employee' => 60.0, 'employer' => 84.0],
            $calculator->superannuation(1000, true, true, false, false, false));
        self::assertFalse($calculator->superannuation(1000, false, true, true, false, false)['eligible']);
        self::assertTrue($calculator->superannuation(1000, false, true, true, true, false)['eligible']);
        self::assertFalse($calculator->superannuation(1000, true, false, false, false, false)['eligible']);
    }
}
