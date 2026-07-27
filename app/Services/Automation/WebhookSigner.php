<?php

namespace App\Services\Automation;

class WebhookSigner
{
    public function secretFor(int $workflowId): string
    {
        return hash_hmac('sha256', "workflow:{$workflowId}", (string) config('app.key'));
    }

    public function signature(int $workflowId, string $timestamp, string $payload): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $this->secretFor($workflowId));
    }
}
