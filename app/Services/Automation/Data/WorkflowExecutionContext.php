<?php

namespace App\Services\Automation\Data;

use App\Models\AutomationRun;
use App\Models\AutomationStepRun;
use App\Models\Contact;
use App\Models\Funnel;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Automation\Exceptions\PermanentAutomationException;

class WorkflowExecutionContext
{
    private array $values;

    public function __construct(
        public AutomationRun $run,
        public AutomationStepRun $step,
    ) {
        $this->values = $run->context ?? [];
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->values, $path, $default);
    }

    public function set(string $path, mixed $value): void
    {
        data_set($this->values, $path, $value);
    }

    public function owner(): User
    {
        return $this->run->user ?? User::findOrFail($this->run->user_id);
    }

    public function contact(): Contact
    {
        $contact = $this->run->contact_id
            ? $this->owner()->contacts()->find($this->run->contact_id)
            : null;

        if (! $contact) {
            throw new PermanentAutomationException('The workflow contact is no longer available.', 'missing_contact');
        }

        return $contact;
    }

    public function funnel(): ?Funnel
    {
        return $this->run->funnel_id
            ? $this->owner()->funnels()->find($this->run->funnel_id)
            : null;
    }

    public function opportunity(): ?Opportunity
    {
        return $this->run->opportunity_id
            ? $this->owner()->opportunities()->find($this->run->opportunity_id)
            : null;
    }

    public function pipeline(int $id): Pipeline
    {
        $pipeline = $this->owner()->pipelines()->find($id);
        if (! $pipeline) {
            throw new PermanentAutomationException('The selected pipeline is no longer available.', 'missing_pipeline');
        }

        return $pipeline;
    }

    public function stage(Pipeline $pipeline, int $id): PipelineStage
    {
        $stage = $pipeline->stages()->find($id);
        if (! $stage) {
            throw new PermanentAutomationException('The selected pipeline stage is no longer available.', 'missing_stage');
        }

        return $stage;
    }

    public function persistContext(): void
    {
        $this->run->context = $this->values;
        $this->run->save();
    }
}
