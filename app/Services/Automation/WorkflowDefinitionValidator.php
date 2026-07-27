<?php

namespace App\Services\Automation;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class WorkflowDefinitionValidator
{
    private const TRIGGERS = [
        'funnel.form_submitted',
        'contact.created',
        'contact.status_changed',
        'opportunity.created',
        'opportunity.stage_changed',
        'opportunity.status_changed',
    ];

    private const NODE_TYPES = [
        'send_email',
        'wait',
        'condition',
        'update_contact',
        'create_opportunity',
        'move_opportunity',
        'notify_owner',
        'webhook',
        'end',
    ];

    private const OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'is_empty',
        'is_not_empty',
        'greater_than',
        'greater_than_or_equal',
        'less_than',
        'less_than_or_equal',
    ];

    private const CONDITION_FIELDS = [
        'contact.name',
        'contact.email',
        'contact.phone',
        'contact.status',
        'contact.source',
        'funnel.id',
        'funnel.name',
        'submission.form_id',
        'opportunity.id',
        'opportunity.title',
        'opportunity.value',
        'opportunity.status',
        'pipeline.id',
        'pipeline.name',
        'stage.id',
        'stage.name',
        'event.type',
    ];

    private const MERGE_FIELDS = [
        'contact.id',
        'contact.name',
        'contact.email',
        'contact.phone',
        'contact.status',
        'funnel.id',
        'funnel.name',
        'funnel.slug',
        'submission.form_id',
        'opportunity.id',
        'opportunity.title',
        'opportunity.value',
        'opportunity.status',
        'pipeline.name',
        'stage.name',
        'event.occurred_at',
        'unsubscribe_url',
    ];

    public function __construct(private WebhookUrlGuard $webhookUrlGuard) {}

    /**
     * @return list<string>
     */
    public function validate(array $definition, User $owner, bool $resolveWebhookHosts = false): array
    {
        $errors = [];

        if (($definition['schema_version'] ?? null) !== 1) {
            $errors[] = 'The workflow schema version must be 1.';
        }

        $trigger = $definition['trigger'] ?? null;
        if (! is_array($trigger) || ! in_array($trigger['type'] ?? null, self::TRIGGERS, true)) {
            $errors[] = 'Select a supported workflow trigger.';
        } else {
            $errors = [...$errors, ...$this->validateTrigger($trigger, $owner)];
        }

        $nodes = $definition['nodes'] ?? null;
        if (! is_array($nodes) || $nodes === []) {
            return [...$errors, 'Add at least one workflow step.'];
        }

        if (count($nodes) > config('automation.max_nodes', 50)) {
            $errors[] = 'A workflow may contain at most '.config('automation.max_nodes', 50).' steps.';
        }

        $nodesById = [];
        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                $errors[] = 'Workflow step '.($index + 1).' is invalid.';

                continue;
            }

            $id = $node['id'] ?? null;
            if (! is_string($id) || $id === '' || strlen($id) > 100) {
                $errors[] = 'Every workflow step needs a stable ID.';

                continue;
            }

            if (isset($nodesById[$id])) {
                $errors[] = "Workflow step ID {$id} is duplicated.";
            }

            $nodesById[$id] = $node;
            $errors = [...$errors, ...$this->validateNode($node, $owner, $resolveWebhookHosts)];
        }

        $startNodeId = $definition['start_node_id'] ?? null;
        if (! is_string($startNodeId) || ! isset($nodesById[$startNodeId])) {
            $errors[] = 'Choose a valid first workflow step.';

            return array_values(array_unique($errors));
        }

        $visited = [];
        $visiting = [];
        $hasPathError = false;
        $walk = function (string $nodeId, int $conditionDepth) use (&$walk, &$visited, &$visiting, &$hasPathError, &$errors, $nodesById): bool {
            if (isset($visiting[$nodeId])) {
                $errors[] = 'Workflow cycles are not supported.';
                $hasPathError = true;

                return false;
            }

            if (isset($visited[$nodeId])) {
                return $visited[$nodeId];
            }

            $node = $nodesById[$nodeId] ?? null;
            if (! $node) {
                $errors[] = "Workflow step {$nodeId} points to a missing step.";
                $hasPathError = true;

                return false;
            }

            $visiting[$nodeId] = true;
            $type = $node['type'] ?? null;

            if ($type === 'end') {
                unset($visiting[$nodeId]);

                return $visited[$nodeId] = true;
            }

            if ($type === 'condition') {
                $conditionDepth++;
                if ($conditionDepth > config('automation.max_condition_depth', 5)) {
                    $errors[] = 'Workflow conditions are nested too deeply.';
                    $hasPathError = true;
                }

                $yes = $node['yes_node_id'] ?? null;
                $no = $node['no_node_id'] ?? null;
                if (! is_string($yes) || ! is_string($no)) {
                    $errors[] = "Condition {$nodeId} needs Yes and No destinations.";
                    unset($visiting[$nodeId]);

                    return $visited[$nodeId] = false;
                }

                $ends = $walk($yes, $conditionDepth) && $walk($no, $conditionDepth);
                unset($visiting[$nodeId]);

                return $visited[$nodeId] = $ends;
            }

            $next = $node['next_node_id'] ?? null;
            if (! is_string($next)) {
                $errors[] = "Workflow step {$nodeId} needs a next step.";
                unset($visiting[$nodeId]);

                return $visited[$nodeId] = false;
            }

            $ends = $walk($next, $conditionDepth);
            unset($visiting[$nodeId]);

            return $visited[$nodeId] = $ends;
        };

        if (! $walk($startNodeId, 0) && ! $hasPathError) {
            $errors[] = 'Every workflow branch must reach an End step.';
        }

        $unreachable = array_diff(array_keys($nodesById), array_keys($visited));
        if ($unreachable !== []) {
            $errors[] = 'Remove disconnected workflow steps: '.implode(', ', $unreachable).'.';
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(array $definition, User $owner, bool $resolveWebhookHosts = false): void
    {
        $errors = $this->validate($definition, $owner, $resolveWebhookHosts);

        if ($errors !== []) {
            throw ValidationException::withMessages(['definition' => $errors]);
        }
    }

    /**
     * @return list<string>
     */
    private function validateTrigger(array $trigger, User $owner): array
    {
        $errors = [];
        $config = is_array($trigger['config'] ?? null) ? $trigger['config'] : [];
        $type = $trigger['type'];

        if (isset($config['funnel_id']) && ! $owner->funnels()->whereKey($config['funnel_id'])->exists()) {
            $errors[] = 'The trigger funnel is not available.';
        }

        if (isset($config['pipeline_id']) && ! $owner->pipelines()->whereKey($config['pipeline_id'])->exists()) {
            $errors[] = 'The trigger pipeline is not available.';
        }

        if (isset($config['stage_id'])) {
            $stage = $owner->pipelines()->whereHas('stages', fn ($query) => $query->whereKey($config['stage_id']))->exists();
            if (! $stage) {
                $errors[] = 'The trigger stage is not available.';
            }
        }

        if ($type === 'funnel.form_submitted' && isset($config['form_id']) && (! is_string($config['form_id']) || strlen($config['form_id']) > 100)) {
            $errors[] = 'The trigger form ID is invalid.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateNode(array $node, User $owner, bool $resolveWebhookHosts): array
    {
        $errors = [];
        $type = $node['type'] ?? null;
        $id = is_string($node['id'] ?? null) ? $node['id'] : 'unknown';
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        if (! in_array($type, self::NODE_TYPES, true)) {
            return ["Workflow step {$id} has an unsupported type."];
        }

        if ($type === 'send_email') {
            if (! in_array($config['purpose'] ?? null, ['marketing', 'transactional'], true)) {
                $errors[] = "Email step {$id} needs a purpose.";
            }
            if (! is_string($config['subject'] ?? null) || trim($config['subject']) === '' || strlen($config['subject']) > 255) {
                $errors[] = "Email step {$id} needs a subject under 255 characters.";
            }
            if (! is_string($config['body'] ?? null) || trim($config['body']) === '' || strlen($config['body']) > 50000) {
                $errors[] = "Email step {$id} needs a body under 50,000 characters.";
            }
            if (isset($config['reply_to']) && $config['reply_to'] !== '' && filter_var($config['reply_to'], FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = "Email step {$id} has an invalid reply-to address.";
            }
            $errors = [...$errors, ...$this->validateMergeFields($id, [$config['subject'] ?? '', $config['body'] ?? ''])];
        }

        if ($type === 'notify_owner') {
            if (! is_string($config['subject'] ?? null) || trim($config['subject']) === '') {
                $errors[] = "Owner notification {$id} needs a subject.";
            }
            if (! is_string($config['body'] ?? null) || trim($config['body']) === '') {
                $errors[] = "Owner notification {$id} needs a body.";
            }
            $errors = [...$errors, ...$this->validateMergeFields($id, [$config['subject'] ?? '', $config['body'] ?? ''])];
        }

        if ($type === 'wait') {
            $amount = filter_var($config['amount'] ?? null, FILTER_VALIDATE_INT);
            $unit = $config['unit'] ?? null;
            $minutes = match ($unit) {
                'minutes' => (int) $amount,
                'hours' => (int) $amount * 60,
                'days' => (int) $amount * 1440,
                default => 0,
            };
            if ($amount === false || $amount < 1 || $minutes < 1 || $minutes > 525600) {
                $errors[] = "Wait step {$id} must be between one minute and 365 days.";
            }
        }

        if ($type === 'condition') {
            $field = $config['field'] ?? null;
            if (! $this->isConditionField($field)) {
                $errors[] = "Condition {$id} uses an unsupported field.";
            }
            if (! in_array($config['operator'] ?? null, self::OPERATORS, true)) {
                $errors[] = "Condition {$id} uses an unsupported operator.";
            }
        }

        if ($type === 'update_contact') {
            $status = $config['status'] ?? null;
            if ($status !== null && ! in_array($status, ['new', 'contacted', 'qualified', 'won', 'lost'], true)) {
                $errors[] = "Contact update {$id} has an invalid status.";
            }
            foreach (['add_tags', 'remove_tags'] as $key) {
                if (isset($config[$key]) && (! is_array($config[$key]) || count($config[$key]) > 20)) {
                    $errors[] = "Contact update {$id} has invalid tags.";
                }
            }
            if ($status === null && empty($config['add_tags']) && empty($config['remove_tags'])) {
                $errors[] = "Contact update {$id} has nothing to change.";
            }
        }

        if (in_array($type, ['create_opportunity', 'move_opportunity'], true)) {
            $pipeline = isset($config['pipeline_id']) ? $owner->pipelines()->find($config['pipeline_id']) : null;
            if (! $pipeline) {
                $errors[] = "Opportunity step {$id} needs an owned pipeline.";
            } elseif (! isset($config['stage_id']) || ! $pipeline->stages()->whereKey($config['stage_id'])->exists()) {
                $errors[] = "Opportunity step {$id} needs a stage from its pipeline.";
            }

            if ($type === 'create_opportunity' && (float) ($config['value'] ?? 0) < 0) {
                $errors[] = "Opportunity step {$id} cannot use a negative value.";
            }
            if ($type === 'create_opportunity') {
                $errors = [...$errors, ...$this->validateMergeFields($id, [(string) ($config['title'] ?? '')])];
            }
        }

        if ($type === 'webhook') {
            try {
                $this->webhookUrlGuard->assertSafe((string) ($config['url'] ?? ''), $resolveWebhookHosts);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = "Webhook step {$id}: {$exception->getMessage()}";
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $templates
     * @return list<string>
     */
    private function validateMergeFields(string $nodeId, array $templates): array
    {
        $errors = [];

        foreach ($templates as $template) {
            preg_match_all('/{{\s*([^{}]+?)\s*}}/', $template, $matches);
            foreach ($matches[1] ?? [] as $field) {
                if (! $this->isMergeField(trim($field))) {
                    $errors[] = "Workflow step {$nodeId} uses unknown merge field {$field}.";
                }
            }
        }

        return $errors;
    }

    private function isMergeField(mixed $field): bool
    {
        return is_string($field)
            && (in_array($field, self::MERGE_FIELDS, true)
                || str_starts_with($field, 'submission.fields.')
                || str_starts_with($field, 'submission.attribution.'));
    }

    private function isConditionField(mixed $field): bool
    {
        return is_string($field)
            && (in_array($field, self::CONDITION_FIELDS, true)
                || str_starts_with($field, 'submission.fields.')
                || str_starts_with($field, 'submission.attribution.'));
    }
}
