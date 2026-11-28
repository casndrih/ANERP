<?php

namespace App\\Services;

use InvalidArgumentException;

final class PngProcurementTaxCalculator
{
    public const STANDARD_GST_RATE = 10.0;

    /**
     * Calculate GST on a domestic supply. Exempt and zero-rated supplies
     * both produce zero GST but remain distinct for reporting.
     *
     * @return array{treatment:string, taxable_value:float, gst_rate:float, gst_amount:float, total:float}
     */
    public function domesticSupply(float $netValue, string $treatment = 'standard', bool $supplierRegistered = true): array
    {
        if ($netValue < 0 || !in_array($treatment, ['standard', 'zero_rated', 'exempt'], true)) {
            throw new InvalidArgumentException('Invalid domestic GST input.');
        }

        $rate = $treatment === 'standard' && $supplierRegistered ? self::STANDARD_GST_RATE : 0.0;
        $gst = round($netValue * $rate / 100, 2);

        return [
            'treatment' => $treatment,
            'taxable_value' => round($netValue, 2),
            'gst_rate' => $rate,
            'gst_amount' => $gst,
            'total' => round($netValue + $gst, 2),
        ];
    }

    /**
     * Import GST base follows GST Act 2003 s.6(2): customs value, customs
     * duty, excise, and freight/insurance to PNG when not already included.
     * Duty rate must come from the current Customs tariff/assessment; it is
     * deliberately not guessed from a generic product category.
     *
     * @return array{customs_value:float, duty_rate:float, customs_duty:float, excise:float, freight_insurance:float, gst_base:float, import_gst_rate:float, import_gst:float, total_border_taxes:float}
     */
    public function importAssessment(
        float $customsValue,
        float $verifiedDutyRate,
        float $excise = 0.0,
        float $freightInsuranceNotIncluded = 0.0,
        bool $gstExempt = false
    ): array {
        if ($customsValue < 0 || $verifiedDutyRate < 0 || $verifiedDutyRate > 100 || $excise < 0 || $freightInsuranceNotIncluded < 0) {
            throw new InvalidArgumentException('Invalid customs assessment input.');
        }

        $customsValue = round($customsValue, 2);
        $duty = round($customsValue * $verifiedDutyRate / 100, 2);
        $excise = round($excise, 2);
        $freight = round($freightInsuranceNotIncluded, 2);
        $gstBase = round($customsValue + $duty + $excise + $freight, 2);
        $gstRate = $gstExempt ? 0.0 : self::STANDARD_GST_RATE;
        $gst = round($gstBase * $gstRate / 100, 2);

        return [
            'customs_value' => $customsValue,
            'duty_rate' => $verifiedDutyRate,
            'customs_duty' => $duty,
            'excise' => $excise,
            'freight_insurance' => $freight,
            'gst_base' => $gstBase,
            'import_gst_rate' => $gstRate,
            'import_gst' => $gst,
            'total_border_taxes' => round($duty + $gst + $excise, 2),
        ];
    }
}
