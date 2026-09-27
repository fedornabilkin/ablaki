<?php
namespace common\services\game;

/** Stable public reason; internal SQL/exception messages never enter the DTO. */
class GameError extends \RuntimeException
{
    public $reason;
    public $status;
    public $details;
    public function __construct(string $reason, string $message, int $status = 409, array $details = [])
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->status = $status;
        $this->details = $details;
    }
}
