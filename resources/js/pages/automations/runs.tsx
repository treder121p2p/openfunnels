import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import type { AutomationRunStatus } from '@/types/automation';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Clock3, Search, UserRound, Workflow } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Automations', href: '/automations' },
    { title: 'Run history', href: '/automations/runs' },
];

interface Run {
    id: string;
    status: AutomationRunStatus;
    current_node_id: string | null;
    started_at: string | null;
    next_resume_at: string | null;
    finished_at: string | null;
    last_error: string | null;
    created_at: string;
    workflow: { id: number; name: string };
    contact: { id: number; name: string | null; email: string } | null;
}

interface Props {
    runs: {
        data: Run[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total: number;
    };
    workflows: Array<{ id: number; name: string }>;
    filters: { workflow_id: string; status: string; search: string };
}

const statusStyles: Record<AutomationRunStatus, string> = {
    queued: 'border-sky-500/30 bg-sky-500/10 text-sky-500',
    running: 'border-violet-500/30 bg-violet-500/10 text-violet-500',
    waiting: 'border-amber-500/30 bg-amber-500/10 text-amber-500',
    completed: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
    failed: 'border-red-500/30 bg-red-500/10 text-red-500',
    cancelled: 'border-zinc-500/30 bg-zinc-500/10 text-zinc-500',
};

function paginationLabel(label: string): string {
    if (label.includes('Previous')) return 'Previous';
    if (label.includes('Next')) return 'Next';
    return label.replace(/&[^;]+;/g, '');
}

export default function AutomationRuns({ runs, workflows, filters }: Props) {
    const [search, setSearch] = useState(filters.search);

    const filter = (changes: Partial<Props['filters']>) => {
        router.get(route('automation-runs.index'), { ...filters, ...changes }, { preserveState: true, replace: true });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        filter({ search });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Automation run history" />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4 md:p-6">
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-bold">
                        <Clock3 className="h-6 w-6 text-primary" />
                        Workflow run history
                    </h1>
                    <p className="mt-1 text-muted-foreground">Inspect every enrollment, wait, action, suppression, and failure.</p>
                </header>

                <form onSubmit={submit} className="grid gap-2 md:grid-cols-[1fr_240px_200px_auto]">
                    <div className="relative">
                        <Search className="absolute top-2.5 left-3 h-4 w-4 text-muted-foreground" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Contact name or email"
                            className="w-full rounded-lg border border-border bg-background py-2 pr-3 pl-9"
                        />
                    </div>
                    <select
                        value={filters.workflow_id}
                        onChange={(event) => filter({ workflow_id: event.target.value })}
                        className="rounded-lg border border-border bg-background px-3 py-2"
                    >
                        <option value="">All workflows</option>
                        {workflows.map((workflow) => (
                            <option key={workflow.id} value={workflow.id}>
                                {workflow.name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={filters.status}
                        onChange={(event) => filter({ status: event.target.value })}
                        className="rounded-lg border border-border bg-background px-3 py-2"
                    >
                        <option value="">All statuses</option>
                        {Object.keys(statusStyles).map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </select>
                    <button className="rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-muted">Filter</button>
                </form>

                <div className="overflow-hidden rounded-xl border border-border bg-card">
                    {runs.data.length === 0 ? (
                        <div className="grid min-h-56 place-items-center p-8 text-center">
                            <div>
                                <Workflow className="mx-auto mb-3 h-9 w-9 text-muted-foreground" />
                                <p className="font-medium">No workflow runs match these filters.</p>
                            </div>
                        </div>
                    ) : (
                        <div className="divide-y divide-border">
                            {runs.data.map((run) => (
                                <Link
                                    key={run.id}
                                    href={route('automation-runs.show', run.id)}
                                    className="grid gap-3 p-4 transition-colors hover:bg-muted/50 md:grid-cols-[minmax(0,1fr)_220px_160px_160px_24px] md:items-center"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold">{run.workflow.name}</p>
                                        <p className="mt-1 flex items-center gap-1.5 truncate text-sm text-muted-foreground">
                                            <UserRound className="h-3.5 w-3.5 shrink-0" />
                                            {run.contact ? run.contact.name || run.contact.email : 'Contact removed'}
                                        </p>
                                    </div>
                                    <code className="truncate text-xs text-muted-foreground">{run.id}</code>
                                    <span className={`w-fit rounded-full border px-2 py-0.5 text-xs font-medium ${statusStyles[run.status]}`}>
                                        {run.status}
                                    </span>
                                    <time className="text-sm text-muted-foreground">{new Date(run.created_at).toLocaleString()}</time>
                                    <ArrowRight className="h-4 w-4 text-muted-foreground" />
                                </Link>
                            ))}
                        </div>
                    )}
                </div>

                {runs.links.length > 3 && (
                    <nav className="flex flex-wrap justify-center gap-1" aria-label="Run history pagination">
                        {runs.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={`${link.label}-${index}`}
                                    href={link.url}
                                    className={`rounded-md border px-3 py-1.5 text-sm ${
                                        link.active ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted'
                                    }`}
                                >
                                    {paginationLabel(link.label)}
                                </Link>
                            ) : null,
                        )}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}
