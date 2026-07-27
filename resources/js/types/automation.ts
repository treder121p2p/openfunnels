export type AutomationTriggerType =
    | 'funnel.form_submitted'
    | 'contact.created'
    | 'contact.status_changed'
    | 'opportunity.created'
    | 'opportunity.stage_changed'
    | 'opportunity.status_changed';

export type AutomationEnrollmentPolicy = 'once_per_contact' | 'after_completion' | 'every_event';
export type AutomationWorkflowStatus = 'draft' | 'active' | 'paused' | 'archived';
export type AutomationRunStatus = 'queued' | 'running' | 'waiting' | 'completed' | 'failed' | 'cancelled';
export type ConditionOperator =
    | 'equals'
    | 'not_equals'
    | 'contains'
    | 'not_contains'
    | 'is_empty'
    | 'is_not_empty'
    | 'greater_than'
    | 'greater_than_or_equal'
    | 'less_than'
    | 'less_than_or_equal';

export interface AutomationTrigger {
    type: AutomationTriggerType;
    config: {
        funnel_id?: number | null;
        pipeline_id?: number | null;
        stage_id?: number | null;
        form_id?: string;
        from_status?: string;
        to_status?: string;
        status?: string;
        source?: string;
        contact_occurrence?: 'new' | 'repeat' | '';
        field?: string;
        operator?: ConditionOperator;
        value?: string | number;
        min_value?: number;
    };
}

interface AutomationNodeBase {
    id: string;
    config: Record<string, unknown>;
}

export interface SendEmailNode extends AutomationNodeBase {
    type: 'send_email';
    config: {
        purpose: 'marketing' | 'transactional';
        subject: string;
        body: string;
        reply_to?: string;
    };
    next_node_id: string;
}

export interface WaitNode extends AutomationNodeBase {
    type: 'wait';
    config: { amount: number; unit: 'minutes' | 'hours' | 'days' };
    next_node_id: string;
}

export interface ConditionNode extends AutomationNodeBase {
    type: 'condition';
    config: { field: string; operator: ConditionOperator; value?: string | number };
    yes_node_id: string;
    no_node_id: string;
}

export interface UpdateContactNode extends AutomationNodeBase {
    type: 'update_contact';
    config: { status?: string | null; add_tags?: string[]; remove_tags?: string[] };
    next_node_id: string;
}

export interface CreateOpportunityNode extends AutomationNodeBase {
    type: 'create_opportunity';
    config: { pipeline_id: number | null; stage_id: number | null; value: number; title?: string };
    next_node_id: string;
}

export interface MoveOpportunityNode extends AutomationNodeBase {
    type: 'move_opportunity';
    config: { pipeline_id: number | null; stage_id: number | null };
    next_node_id: string;
}

export interface NotifyOwnerNode extends AutomationNodeBase {
    type: 'notify_owner';
    config: { subject: string; body: string };
    next_node_id: string;
}

export interface WebhookNode extends AutomationNodeBase {
    type: 'webhook';
    config: { url: string };
    next_node_id: string;
}

export interface EndNode extends AutomationNodeBase {
    type: 'end';
    config: Record<string, never>;
}

export type AutomationNode =
    | SendEmailNode
    | WaitNode
    | ConditionNode
    | UpdateContactNode
    | CreateOpportunityNode
    | MoveOpportunityNode
    | NotifyOwnerNode
    | WebhookNode
    | EndNode;

export interface AutomationDefinition {
    schema_version: 1;
    trigger: AutomationTrigger;
    start_node_id: string;
    nodes: AutomationNode[];
}

export interface AutomationDraft {
    name: string;
    description: string;
    enrollment_policy: AutomationEnrollmentPolicy;
    definition: AutomationDefinition;
}

export interface AutomationPipelineOption {
    id: number;
    name: string;
    currency: string;
    stages: Array<{ id: number; pipeline_id: number; name: string; position: number }>;
}

export interface AutomationEditorOptions {
    funnels: Array<{ id: number; name: string }>;
    pipelines: AutomationPipelineOption[];
    contacts: Array<{ id: number; name: string | null; email: string }>;
    webhook_signing_secret: string;
}
