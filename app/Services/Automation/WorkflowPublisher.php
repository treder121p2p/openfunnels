<?php

namespace App\Services\Automation;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use Illuminate\Support\Facades\DB;

class WorkflowPublisher
{
    public function __construct(private WorkflowDefinitionValidator $validator) {}

    public function publish(AutomationWorkflow $workflow): AutomationWorkflowVersion
    {
        $this->validator->assertValid($workflow->draft_definition, $workflow->user, true);

        $normalized = $this->normalize($workflow->draft_definition);
        $checksum = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($workflow, $normalized, $checksum) {
            $workflow = AutomationWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
            $existing = $workflow->versions()->where('checksum', $checksum)->first();

            if ($existing) {
                $workflow->update([
                    'active_version_id' => $existing->id,
                    'trigger_type' => data_get($existing->definition, 'trigger.type'),
                    'status' => 'active',
                ]);

                return $existing;
            }

            $version = $workflow->versions()->create([
                'version' => ((int) $workflow->versions()->max('version')) + 1,
                'definition' => $normalized,
                'checksum' => $checksum,
                'published_at' => now(),
            ]);

            $workflow->update([
                'active_version_id' => $version->id,
                'trigger_type' => data_get($normalized, 'trigger.type'),
                'status' => 'active',
            ]);

            return $version;
        });
    }

    private function normalize(array $definition): array
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (array_is_list($value)) {
                return array_map($normalize, $value);
            }

            ksort($value);

            return array_map($normalize, $value);
        };

        return $normalize($definition);
    }
}
