<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Service;

/** A refused operator action. The message is German and shown as-is; the code is the HTTP status. */
final class LedgerException extends \RuntimeException
{
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
