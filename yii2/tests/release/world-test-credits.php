<?php
require dirname(__DIR__, 2) . '/common/modules/world/modules/economy/value/Money.php';
require dirname(__DIR__, 2) . '/common/modules/world/modules/economy/value/TestCreditConversion.php';

use common\modules\world\modules\economy\value\Money;
use common\modules\world\modules\economy\value\TestCreditConversion;

foreach (['0' => '0.0000', '1.2300000000000002' => '1.2300', '12.34565' => '12.3457', '-12.34565' => '-12.3457', '9.99995' => '10.0000', '5e-5' => '0.0001', '4.999e-5' => '0.0000', '1.23456e2' => '123.4560', '0001.20' => '1.2000', '-0.0000' => '0.0000'] as $raw => $expected) {
    if (TestCreditConversion::amount((string)$raw)->decimal() !== $expected) throw new RuntimeException('Wrong test conversion: ' . $raw);
}
foreach (['-0.00000001', '-1', 'NaN', 'Infinity', '', '1e999'] as $raw) {
    try { TestCreditConversion::amount($raw, true); }
    catch (InvalidArgumentException | OverflowException $e) { continue; }
    throw new RuntimeException('Invalid personal value was accepted: ' . $raw);
}
try { Money::parse('1.23456'); throw new RuntimeException('Production parser became permissive.'); }
catch (InvalidArgumentException $e) {}
echo "PASS test credit rounding, exponent notation, overflow, negative balances and strict production parser\n";
