<?php
namespace common\modules\economy\value;

/** Four decimal places, checked signed int64 arithmetic, no binary floating point. */
final class Money
{
    public const SCALE = 10000;
    public const MAX_UNITS = 9000000000000000000;
    private $units;
    private function __construct(int $units) { $this->units = $units; }
    public static function parse(string $amount): self
    {
        if (PHP_INT_SIZE < 8) throw new \RuntimeException('Exact credits require a 64-bit PHP runtime.');
        if (!preg_match('/^(-?)(0|[1-9][0-9]{0,14})(?:\.([0-9]{1,4}))?$/D', $amount, $matches)) throw new \InvalidArgumentException('Expected a decimal string with at most four fractional digits.');
        $digits = ltrim($matches[2] . str_pad($matches[3] ?? '', 4, '0'), '0'); $digits = $digits === '' ? '0' : $digits;
        $maximum = (string)self::MAX_UNITS;
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) throw new \OverflowException('Credit amount exceeds the supported range.');
        return new self($matches[1] === '-' ? -(int)$digits : (int)$digits);
    }
    public function decimal(): string
    {
        $units = abs($this->units);
        return ($this->units < 0 ? '-' : '') . intdiv($units, self::SCALE) . '.' . str_pad((string)($units % self::SCALE), 4, '0', STR_PAD_LEFT);
    }
    /** Compatibility boundary only. New APIs must accept decimal strings directly. */
    public static function fromLegacy(float $amount): self
    {
        if (!is_finite($amount) || abs($amount) > 100000000000) throw new \InvalidArgumentException('Legacy amount is outside the exact adapter range.');
        $decimal = number_format($amount, 4, '.', '');
        if (abs($amount - (float)$decimal) > 0.00000001) throw new \InvalidArgumentException('Legacy amount needs an explicit rounding decision.');
        return self::parse($decimal);
    }
    public function add(self $other): self
    {
        if (($other->units > 0 && $this->units > self::MAX_UNITS - $other->units) || ($other->units < 0 && $this->units < -self::MAX_UNITS - $other->units)) throw new \OverflowException('Credit sum exceeds the supported range.');
        return new self($this->units + $other->units);
    }
    public function subtract(self $other): self { return $this->add(new self(-$other->units)); }
    public function multiply(int $factor): self
    {
        if ($factor < 0) throw new \InvalidArgumentException('Expected a non-negative multiplier.');
        if ($factor !== 0 && abs($this->units) > intdiv(self::MAX_UNITS, $factor)) throw new \OverflowException('Credit product exceeds the supported range.');
        return new self($this->units * $factor);
    }
    public function compare(self $other): int { return $this->units <=> $other->units; }
    public function divideExact(int $divisor): self
    {
        if ($divisor < 1 || $this->units % $divisor !== 0) throw new \InvalidArgumentException('Amount requires an explicit rounding decision.');
        return new self(intdiv($this->units, $divisor));
    }
    public function isNegative(): bool { return $this->units < 0; }
    public function isZero(): bool { return $this->units === 0; }
    /** Exact proportional cost rounded up to 0.0001 Cr; avoids multiplying the full int64 amount. */
    public function ratioCeil(int $numerator, int $denominator): self
    {
        if ($this->units < 0 || $denominator < 1 || $denominator > 1000000 || $numerator < 0 || $numerator > $denominator) throw new \InvalidArgumentException('Invalid proportional amount.');
        $fraction = ($this->units % $denominator) * $numerator;
        return new self(intdiv($this->units, $denominator) * $numerator + intdiv($fraction + $denominator - 1, $denominator));
    }
    /** Floor to 0.0001 Cr, carrying sub-unit fractions across operations. Never multiplies int64 by a rate. */
    public function portion(int $basisPoints, int $carry = 0): array
    {
        if ($this->units < 0 || $basisPoints < 0 || $basisPoints > 10000 || $carry < 0 || $carry >= 10000) throw new \InvalidArgumentException('Invalid rate or fractional carry.');
        $fraction = ($this->units % 10000) * $basisPoints + $carry;
        return ['amount' => new self(intdiv($this->units, 10000) * $basisPoints + intdiv($fraction, 10000)), 'carry' => $fraction % 10000];
    }
}
