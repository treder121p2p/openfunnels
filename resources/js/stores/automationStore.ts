import type { AutomationDefinition, AutomationDraft } from '@/types/automation';
import { produce } from 'immer';
import { create } from 'zustand';

const MAX_HISTORY = 50;

function snapshot(draft: AutomationDraft): AutomationDraft {
    return JSON.parse(JSON.stringify(draft)) as AutomationDraft;
}

interface AutomationStore {
    draft: AutomationDraft | null;
    history: AutomationDraft[];
    historyIndex: number;
    isDirty: boolean;
    isSaving: boolean;
    setDraft: (draft: AutomationDraft) => void;
    updateMetadata: (updates: Partial<Omit<AutomationDraft, 'definition'>>) => void;
    updateDefinition: (definition: AutomationDefinition) => void;
    undo: () => void;
    redo: () => void;
    canUndo: () => boolean;
    canRedo: () => boolean;
    setSaving: (saving: boolean) => void;
    markClean: () => void;
}

export const useAutomationStore = create<AutomationStore>()((set, get) => {
    const commit = (draft: AutomationDraft) => {
        const state = get();
        const history = [...state.history.slice(0, state.historyIndex + 1), snapshot(draft)].slice(-MAX_HISTORY);
        set({
            draft,
            history,
            historyIndex: history.length - 1,
            isDirty: true,
        });
    };

    return {
        draft: null,
        history: [],
        historyIndex: -1,
        isDirty: false,
        isSaving: false,
        setDraft: (draft) =>
            set({
                draft: snapshot(draft),
                history: [snapshot(draft)],
                historyIndex: 0,
                isDirty: false,
            }),
        updateMetadata: (updates) => {
            const current = get().draft;
            if (!current) return;
            commit({ ...current, ...updates });
        },
        updateDefinition: (definition) => {
            const current = get().draft;
            if (!current) return;
            commit(produce(current, (next) => void (next.definition = definition)));
        },
        undo: () => {
            const state = get();
            if (state.historyIndex <= 0) return;
            const index = state.historyIndex - 1;
            set({ draft: snapshot(state.history[index]), historyIndex: index, isDirty: true });
        },
        redo: () => {
            const state = get();
            if (state.historyIndex >= state.history.length - 1) return;
            const index = state.historyIndex + 1;
            set({ draft: snapshot(state.history[index]), historyIndex: index, isDirty: true });
        },
        canUndo: () => get().historyIndex > 0,
        canRedo: () => get().historyIndex >= 0 && get().historyIndex < get().history.length - 1,
        setSaving: (isSaving) => set({ isSaving }),
        markClean: () => set({ isDirty: false, isSaving: false }),
    };
});
