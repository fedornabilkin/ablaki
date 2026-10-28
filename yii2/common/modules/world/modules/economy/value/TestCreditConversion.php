<?php
namespace common\modules\world\modules\economy\value;

/** Test migration policy: decimal half-up to the wallet quantum, retaining the raw snapshot. */
final class TestCreditConversion
{
    public static function amount(string $raw, bool $personal = false): Money
    {
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]{1,3}))?$/D', $raw, $m)) throw new \InvalidArgumentException('Non-numeric legacy credit.');
        $integer = $m[2]; $fraction = $m[3] ?? ''; $exponent = (int)($m[4] ?? 0);
        if ($personal && $m[1] === '-' && trim($integer . $fraction, '0') !== '') throw new \InvalidArgumentException('Negative personal credit.');
        $digits = $integer . $fraction; $point = strlen($integer) + $exponent;
        if ($point <= 0) { $integer = '0'; $fraction = str_repeat('0', -$point) . $digits; }
        elseif ($point >= strlen($digits)) { $integer = $digits . str_repeat('0', $point - strlen($digits)); $fraction = ''; }
        else { $integer = substr($digits, 0, $point); $fraction = substr($digits, $point); }
        $integer = ltrim($integer, '0'); if ($integer === '') $integer = '0';
        $fraction = str_pad($fraction, 5, '0');
        $amount = Money::parse($integer . '.' . substr($fraction, 0, 4));
        if ($fraction[4] >= '5') $amount = $amount->add(Money::parse('0.0001'));
        return $m[1] === '-' ? Money::parse('0')->subtract($amount) : $amount;
    }
}
