<?php

namespace App\Services\Automation;

use App\Models\AutomationEvent;

class TriggerMatcher
{
    public function matches(array $definition, AutomationEvent $event, array $context): bool
    {
        $trigger = $definition['trigger'] ?? [];
        if (($trigger['type'] ?? null) !== $event->event_type) {
            return false;
        }

        $config = is_array($trigger['config'] ?? null) ? $trigger['config'] : [];
        $payload = $event->payload ?? [];

        $checks = [
            'funnel_id' => $event->funnel_id,
            'pipeline_id' => data_get($context, 'pipeline.id'),
            'stage_id' => data_get($context, 'stage.id'),
            'form_id' => data_get($context, 'submission.form_id'),
            'from_status' => data_get($payload, 'from_status'),
            'to_status' => data_get($payload, 'to_status'),
            'source' => data_get($context, 'opportunity.source', data_get($context, 'contact.source')),
            'status' => data_get($context, 'opportunity.status', data_get($context, 'contact.status')),
        ];

        foreach ($checks as $key => $actual) {
            if (array_key_exists($key, $config) && $config[$key] !== null && $config[$key] !== '' && (string) $config[$key] !== (string) $actual) {
                return false;
            }
        }

        if (isset($config['contact_occurrence'])) {
            $isNew = (bool) data_get($payload, 'is_new_contact', false);
            if ($config['contact_occurrence'] === 'new' && ! $isNew) {
                return false;
            }
            if ($config['contact_occurrence'] === 'repeat' && $isNew) {
                return false;
            }
        }

        if (isset($config['field'], $config['operator'])) {
            return $this->compare(
                data_get($context, (string) $config['field']),
                (string) $config['operator'],
                $config['value'] ?? null,
            );
        }

        if (isset($config['min_value']) && (float) data_get($context, 'opportunity.value', 0) < (float) $config['min_value']) {
            return false;
        }

        return true;
    }

    public function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'equals' => (string) $actual === (string) $expected,
            'not_equals' => (string) $actual !== (string) $expected,
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => ! str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'is_empty' => $actual === null || $actual === '' || $actual === [],
            'is_not_empty' => $actual !== null && $actual !== '' && $actual !== [],
            'greater_than' => (float) $actual > (float) $expected,
            'greater_than_or_equal' => (float) $actual >= (float) $expected,
            'less_than' => (float) $actual < (float) $expected,
            'less_than_or_equal' => (float) $actual <= (float) $expected,
            default => false,
        };
    }
}
