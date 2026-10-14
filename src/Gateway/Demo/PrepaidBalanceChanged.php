<?php
declare(strict_types=1);

namespace OwnPay\Gateway\Demo;

/**
 * Thrown when an applied prepaid code can no longer cover its allocation at redemption time.
 */
final class PrepaidBalanceChanged extends \RuntimeException {}
