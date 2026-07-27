<?php

namespace App\Services\Automation\Actions;

use App\Services\Automation\Contracts\WorkflowAction;
use App\Services\Automation\Data\ActionResult;
use App\Services\Automation\Data\WorkflowExecutionContext;
use App\Services\Automation\Exceptions\PermanentAutomationException;
use App\Services\Automation\Exceptions\RetryableAutomationException;
use App\Services\Automation\WebhookSigner;
use App\Services\Automation\WebhookUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WebhookAction implements WorkflowAction
{
    public function __construct(
        private WebhookUrlGuard $urlGuard,
        private WebhookSigner $signer,
    ) {}

    public function type(): string
    {
        return 'webhook';
    }

    public function execute(WorkflowExecutionContext $context, array $config): ActionResult
    {
        if ($context->owner()->is_demo) {
            return ActionResult::suppressed('External webhooks are disabled in the guest sandbox.');
        }

        $url = (string) $config['url'];
        $ips = $this->urlGuard->assertSafe($url);
        $timestamp = (string) now()->timestamp;
        $payload = [
            'event_id' => $context->run->automation_event_id,
            'run_id' => $context->run->id,
            'workflow_id' => $context->run->workflow_id,
            'event' => $context->get('event'),
            'contact' => $context->get('contact'),
            'funnel' => $context->get('funnel'),
            'opportunity' => $context->get('opportunity'),
        ];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $this->signer->signature($context->run->workflow_id, $timestamp, $encoded);
        $parts = parse_url($url);
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80));
        $options = [
            'allow_redirects' => false,
            'stream' => true,
        ];

        if (defined('CURLOPT_RESOLVE') && isset($parts['host'], $ips[0])) {
            $options['curl'] = [
                CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$ips[0]}"],
            ];
        }

        try {
            $response = Http::connectTimeout(config('automation.webhooks.connect_timeout', 3))
                ->timeout(config('automation.webhooks.timeout', 10))
                ->withOptions($options)
                ->withHeaders([
                    'Idempotency-Key' => $context->step->idempotency_key,
                    'X-OpenFunnels-Timestamp' => $timestamp,
                    'X-OpenFunnels-Signature' => 'sha256='.$signature,
                    'User-Agent' => 'OpenFunnels-Automation/1.0',
                ])
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new RetryableAutomationException('The webhook connection failed.', 'webhook_connection', previous: $exception);
        }

        $status = $response->status();
        if ($status === 408 || $status === 429 || $status >= 500) {
            throw new RetryableAutomationException("The webhook returned HTTP {$status}.", 'webhook_retryable_status');
        }

        if ($status >= 400) {
            throw new PermanentAutomationException("The webhook returned HTTP {$status}.", 'webhook_rejected');
        }

        return ActionResult::completed([
            'status' => $status,
            'host' => (string) ($parts['host'] ?? ''),
            'signed' => true,
        ]);
    }

    public function simulate(WorkflowExecutionContext $context, array $config): ActionResult
    {
        return ActionResult::completed([
            'would_send_webhook' => true,
            'host' => (string) parse_url((string) ($config['url'] ?? ''), PHP_URL_HOST),
            'signed' => true,
        ]);
    }
}
