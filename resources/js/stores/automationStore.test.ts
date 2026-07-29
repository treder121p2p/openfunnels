import type { AutomationDraft } from '@/types/automation';
import { beforeEach, describe, expect, it } from 'vitest';
import { useAutomationStore } from './automationStore';

const draft: AutomationDraft = {
    name: 'Lead follow-up',
    description: '',
    enrollment_policy: 'every_event',
    definition: {
        schema_version: 1,
        trigger: { type: 'contact.created', config: {} },
        start_node_id: 'end',
        nodes: [{ id: 'end', type: 'end', config: {} }],
    },
};

describe('automation store', () => {
    beforeEach(() => {
        useAutomationStore.getState().setDraft(structuredClone(draft));
    });

    it('keeps immutable undo and redo snapshots', () => {
        useAutomationStore.getState().updateMetadata({ name: 'Qualified lead follow-up' });
        useAutomationStore.getState().updateDefinition({
            ...draft.definition,
            trigger: { type: 'contact.status_changed', config: { to_status: 'qualified' } },
        });

        useAutomationStore.getState().undo();
        expect(useAutomationStore.getState().draft?.name).toBe('Qualified lead follow-up');
        expect(useAutomationStore.getState().draft?.definition.trigger.type).toBe('contact.created');

        useAutomationStore.getState().redo();
        expect(useAutomationStore.getState().draft?.definition.trigger.type).toBe('contact.status_changed');
    });

    it('marks only the persisted snapshot clean', () => {
        useAutomationStore.getState().updateMetadata({ description: 'Updated' });
        expect(useAutomationStore.getState().isDirty).toBe(true);

        useAutomationStore.getState().markClean();
        expect(useAutomationStore.getState().isDirty).toBe(false);
        expect(useAutomationStore.getState().canUndo()).toBe(true);
    });

    it('resets transient save state when another workflow loads', () => {
        useAutomationStore.getState().setSaving(true);
        useAutomationStore.getState().setDraft({ ...structuredClone(draft), name: 'Another workflow' });

        expect(useAutomationStore.getState().isSaving).toBe(false);
        expect(useAutomationStore.getState().isDirty).toBe(false);
        expect(useAutomationStore.getState().draft?.name).toBe('Another workflow');
    });
});
