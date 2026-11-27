<?php

namespace Tests\Unit;

use App\Services\ReportingCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class ReportingCalculatorTest extends TestCase
{
    public function test_debit_and_credit_normal_balances_are_calculated_correctly(): void
    {
        self::assertSame(125.50, ReportingCalculator::accountBalance(25, 151, 'credit'));
        self::assertSame(125.50, ReportingCalculator::accountBalance(151, 25, 'debit'));
    }

    public function test_ageing_days_and_buckets_cover_boundaries(): void
    {
        $asOf = Carbon::parse('2026-11-30');
        self::assertSame(0, ReportingCalculator::ageingDays(Carbon::parse('2026-12-01'), $asOf));
        self::assertSame(1, ReportingCalculator::ageingDays(Carbon::parse('2026-11-29'), $asOf));
        self::assertSame(30, ReportingCalculator::ageingDays(Carbon::parse('2026-10-31'), $asOf));
        self::assertSame(31, ReportingCalculator::ageingDays(Carbon::parse('2026-10-30'), $asOf));
        self::assertSame(60, ReportingCalculator::ageingDays(Carbon::parse('2026-10-01'), $asOf));
        self::assertSame(61, ReportingCalculator::ageingDays(Carbon::parse('2026-09-30'), $asOf));
        self::assertSame(90, ReportingCalculator::ageingDays(Carbon::parse('2026-09-01'), $asOf));
        self::assertSame(91, ReportingCalculator::ageingDays(Carbon::parse('2026-08-31'), $asOf));
        self::assertSame('current', ReportingCalculator::ageingBucket(0));
        self::assertSame('1_30', ReportingCalculator::ageingBucket(30));
        self::assertSame('31_60', ReportingCalculator::ageingBucket(31));
        self::assertSame('61_90', ReportingCalculator::ageingBucket(90));
        self::assertSame('over_90', ReportingCalculator::ageingBucket(91));
    }

    public function test_stock_movement_signs_and_transfer_costs_net_to_zero(): void
    {
        $movements = [
            (object)['movement_type'=>'opening','quantity'=>10,'unit_cost'=>5],
            (object)['movement_type'=>'transfer_out','quantity'=>4,'unit_cost'=>5],
            (object)['movement_type'=>'transfer_in','quantity'=>4,'unit_cost'=>5],
            (object)['movement_type'=>'issue','quantity'=>2,'unit_cost'=>5],
        ];
        self::assertSame(1, ReportingCalculator::movementSign('purchase_receipt'));
        self::assertSame(-1, ReportingCalculator::movementSign('adjustment_out'));
        self::assertSame(0, ReportingCalculator::movementSign('unknown'));
        self::assertSame(40.0, ReportingCalculator::movementCostValue($movements));
    }

    public function test_profit_and_loss_net_income_uses_income_less_expenses(): void
    {
        $accounts = [
            (object)['account_type'=>'income','debit_total'=>20,'credit_total'=>500],
            (object)['account_type'=>'expense','debit_total'=>300,'credit_total'=>10],
            (object)['account_type'=>'asset','debit_total'=>1000,'credit_total'=>0],
        ];
        self::assertSame(190.0, ReportingCalculator::netIncome($accounts));
    }
}
