<?php
namespace common\modules\economy\value;

/** Exact SQL aggregate representation; turnover/combined balances can exceed a single wallet. */
final class ReportAmount
{
    public static function decimal($value): string
    {
        $value = $value === null ? '0' : (string)$value;
        if (!preg_match('/^(-?)(0|[1-9][0-9]{0,39})(?:\.([0-9]{1,4}))?$/D', $value, $m)) throw new \RuntimeException('Invalid exact ledger aggregate.');
        $result = $m[2] . '.' . str_pad($m[3] ?? '', 4, '0');
        return ($m[1] === '-' && $result !== '0.0000' ? '-' : '') . $result;
    }
}
