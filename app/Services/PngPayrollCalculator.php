<?php
namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * PNG salary and wages withholding for the Income Tax Act 2025,
 * effective 1 January 2026. Amounts are PGK.
 *
 * The statutory table is fortnightly. Periods are normalized to a
 * fortnightly equivalent; periods longer than 14 days are segmented
 * in accordance with Schedule 6 clause 5. Weekly proration should be
 * checked against any later IRC payroll circular before live use.
 */
class PngPayrollCalculator
{
    public const VERSION = 'PNG-ITA-2025-2026.1';
    public const EFFECTIVE_FROM = '2026-01-01';

    public function salaryAndWagesTax(
        float $periodGross,
        string $residency,
        bool $employmentDeclarationReceived,
        float $annualDependantTaxCredit,
        int $periodDays
    ): float {
        if ($periodGross < 0 || $periodDays < 1) {
            throw new InvalidArgumentException('Payroll gross and period length must be valid.');
        }
        if (!in_array($residency, ['resident', 'non_resident'], true)) {
            throw new InvalidArgumentException('Select resident or non-resident tax status.');
        }
        if ($annualDependantTaxCredit < 0 || $annualDependantTaxCredit > 1050) {
            throw new InvalidArgumentException('Approved annual dependant tax credit must be between K0 and K1,050.');
        }

        $dailyGross = $periodGross / $periodDays;
        $remainingDays = $periodDays;
        $tax = 0.0;

        while ($remainingDays > 0) {
            $segmentDays = min(14, $remainingDays);
            $segmentGross = $dailyGross * $segmentDays;
            $fortnightEquivalent = $segmentGross * 14 / $segmentDays;

            if (!$employmentDeclarationReceived) {
                $segmentTax = $fortnightEquivalent * 0.42;
            } else {
                $segmentTax = $this->fortnightlyProgressiveTax($fortnightEquivalent, $residency);
                $creditForSegment = $residency === 'resident'\n                    ? ($annualDependantTaxCredit / 26) * ($segmentDays / 14)\n                    : 0.0;
                $segmentTax = max(0, $segmentTax - $creditForSegment);
            }

            $tax += $segmentTax * ($segmentDays / 14);
            $remainingDays -= $segmentDays;
        }

        return round($tax, 2);
    }

    public function superannuation(
        float $basePay,
        bool $employerHasAtLeast15Employees,
        bool $continuousServiceOfThreeMonths,
        bool $employerVoluntaryParticipation,
        bool $employeeVoluntaryOptIn,
        bool $exempt
    ): array {
        $eligible = !$exempt && $continuousServiceOfThreeMonths
            && ($employerHasAtLeast15Employees || ($employerVoluntaryParticipation && $employeeVoluntaryOptIn));

        return [
            'eligible' => $eligible,
            'employee' => $eligible ? round($basePay * 0.06, 2) : 0.00,
            'employer' => $eligible ? round($basePay * 0.084, 2) : 0.00,
        ];
    }

    private function fortnightlyProgressiveTax(float $gross, string $residency): float
    {
        $bands = $residency === 'resident'
            ? [[770, 0.00], [1270, 0.30], [2693, 0.35], [9615, 0.40], [INF, 0.42]]
            : [[770, 0.22], [1270, 0.30], [2693, 0.35], [9615, 0.40], [INF, 0.42]];

        $tax = 0.0;
        $lower = 0.0;
        foreach ($bands as [$upper, $rate]) {
            $portion = max(0, min($gross, $upper) - $lower);
            $tax += $portion * $rate;
            if ($gross <= $upper) {
                break;
            }
            $lower = $upper;
        }
        return $tax;
    }
}