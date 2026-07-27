<?php

namespace App\Services\Automation\Data;

use Carbon\CarbonInterface;

class ActionResult
{
    public function __construct(
        public string $status = 'completed',
        public array $output = [],
        public ?string $nextNodeId = null,
        public ?CarbonInterface $resumeAt = null,
        public bool $terminal = false,
    ) {}

    public static function completed(array $output = [], ?string $nextNodeId = null, bool $terminal = false): self
    {
        return new self('completed', $output, $nextNodeId, null, $terminal);
    }

    public static function suppressed(string $reason): self
    {
        return new self('suppressed', ['reason' => $reason]);
    }

    public static function waiting(CarbonInterface $resumeAt): self
    {
        return new self('waiting', ['resume_at' => $resumeAt->toISOString()], null, $resumeAt);
    }
}
