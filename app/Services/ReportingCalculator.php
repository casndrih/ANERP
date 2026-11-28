<?php

namespace App\Services;

use Carbon\CarbonInterface;

final class ReportingCalculator
{
    public static function accountBalance(float $debit, float $credit, string $normalBalance): float
    {
        return round($normalBalance === 'credit' ? $credit - $debit : $debit - $credit, 2);
    }

    public static function ageingDays(?CarbonInterface $dueDate, CarbonInterface $asOf): int
    {
        $due = $dueDate ?? $asOf;
        return max(0, (int) $due->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false));
    }

    public static function ageingBucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => 'over_90',
        };
    }

    public static function movementSign(string $movementType): int
    {
        return match ($movementType) {
            'opening', 'purchase_receipt', 'transfer_in', 'adjustment_in' => 1,
            'issue', 'transfer_out', 'adjustment_out' => -1,
            default => 0,
        };
    }

    public static function movementCostValue(iterable $movements): float
    {
        $value = 0.0;
        foreach ($movements as $movement) {
            $value += self::movementSign((string) $movement->movement_type)
                * (float) $movement->quantity * (float) $movement->unit_cost;
        }
        return round($value, 2);
    }

    public static function netIncome(iterable $accountBalances): float
    {
        $net = 0.0;
        foreach ($accountBalances as $account) {
            $debit = (float) $account->debit_total;
            $credit = (float) $account->credit_total;
            if ($account->account_type === 'income') $net += $credit - $debit;
            if ($account->account_type === 'expense') $net -= $debit - $credit;
        }
        return round($net, 2);
    }
}
