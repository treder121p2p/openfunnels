import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import type { AutomationRunStatus } from '@/types/automation';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Ban, CheckCircle2, Clock3, ExternalLink, Play, RotateCcw, Workflow, XCircle } from 'lucide-react';

interface Step {
    id: string;
    node_id: string;
    node_type: string;
    status: string;
    attempt: number;
    scheduled_for: string | null;
    started_at: string | null;
    finished_at: string | null;
    output: Record<string, unknown>;
    error_code: string | null;
    error_message: string | null;
}

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
    retryable: boolean;
    workflow_version: number;
    contact: { id: number; name: string | null; email: string } | null;
    funnel: { id: number; name: string } | null;
    opportunity: { id: number; title: string } | null;
    event: { type: string; payload: Record<string, unknown>; created_at: string };
    steps: Step[];
}

const stepIcons: Record<string, typeof CheckCircle2> = {
    completed: CheckCircle2,
    suppressed: Ban,
    failed: XCircle,
    waiting: Clock3,
    running: Play,
};

function humanize(value: string): string {
    return value.replace(/[._-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export default function AutomationRunDetail({ run }: { run: Run }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Automations', href: '/automations' },
        { title: 'Run history', href: '/automations/runs' },
        { title: run.id.slice(-8), href: `/automations/runs/${run.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Automation run ${run.id.slice(-8)}`} />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4 md:p-6">
                <header className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div className="flex items-center gap-3">
                        <Link href={route('automation-runs.index')} className="rounded-lg border border-border p-2 hover:bg-muted">
                            <ArrowLeft className="h-4 w-4" />
                        </Link>
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-bold">
                                <Workflow className="h-6 w-6 text-primary" />
                                {run.workflow.name}
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Version {run.workflow_version} · {humanize(run.event.type)} · {run.id}
                            </p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        {run.status === 'failed' && run.retryable && (
                            <button
                                type="button"
                                onClick={() => router.post(route('automation-runs.retry', run.id))}
                                className="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-muted"
                            >
                                <RotateCcw className="h-4 w-4" /> Retry
                            </button>
                        )}
                        {['queued', 'running', 'waiting'].includes(run.status) && (
                            <button
                                type="button"
                                onClick={() => router.post(route('automation-runs.cancel', run.id))}
                                className="inline-flex items-center gap-2 rounded-lg border border-destructive/40 px-4 py-2 text-sm font-medium text-destructive hover:bg-destructive/10"
                            >
                                <Ban className="h-4 w-4" /> Cancel
                            </button>
                        )}
                    </div>
                </header>

                <section className="grid gap-4 md:grid-cols-4">
                    <div className="rounded-xl border border-border bg-card p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">Status</p>
                        <p className="mt-2 font-semibold">{humanize(run.status)}</p>
                    </div>
                    <div className="rounded-xl border border-border bg-card p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">Contact</p>
                        {run.contact ? (
                            <Link
                                href={route('contacts.show', run.contact.id)}
                                className="mt-2 flex items-center gap-1 font-semibold hover:text-primary"
                            >
                                {run.contact.name || run.contact.email} <ExternalLink className="h-3 w-3" />
                            </Link>
                        ) : (
                            <p className="mt-2 text-muted-foreground">Removed</p>
                        )}
                    </div>
                    <div className="rounded-xl border border-border bg-card p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">Started</p>
                        <p className="mt-2 font-semibold">{new Date(run.started_at || run.created_at).toLocaleString()}</p>
                    </div>
                    <div className="rounded-xl border border-border bg-card p-4">
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">Next resume</p>
                        <p className="mt-2 font-semibold">{run.next_resume_at ? new Date(run.next_resume_at).toLocaleString() : '—'}</p>
                    </div>
                </section>

                {run.last_error && (
                    <div className="rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">{run.last_error}</div>
                )}

                <section className="rounded-xl border border-border bg-card p-5">
                    <h2 className="mb-5 text-lg font-semibold">Step timeline</h2>
                    {run.steps.length === 0 ? (
                        <p className="text-sm text-muted-foreground">This run has not claimed its first step yet.</p>
                    ) : (
                        <ol className="space-y-0">
                            {run.steps.map((step, index) => {
                                const Icon = stepIcons[step.status] || Clock3;
                                return (
                                    <li key={step.id} className="relative grid grid-cols-[32px_1fr] gap-3 pb-6 last:pb-0">
                                        {index < run.steps.length - 1 && <span className="absolute top-8 bottom-0 left-[15px] w-px bg-border" />}
                                        <span className="relative z-10 grid h-8 w-8 place-items-center rounded-full border border-border bg-background">
                                            <Icon className="h-4 w-4" />
                                        </span>
                                        <div className="rounded-lg border border-border bg-background p-4">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p className="font-semibold">{humanize(step.node_type)}</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {humanize(step.status)} · attempt {step.attempt}
                                                    </p>
                                                </div>
                                                {step.finished_at && (
                                                    <time className="text-xs text-muted-foreground">
                                                        {new Date(step.finished_at).toLocaleString()}
                                                    </time>
                                                )}
                                            </div>
                                            {Object.keys(step.output).length > 0 && (
                                                <pre className="mt-3 overflow-x-auto rounded-md bg-muted p-3 text-xs">
                                                    {JSON.stringify(step.output, null, 2)}
                                                </pre>
                                            )}
                                            {step.error_message && (
                                                <p className="mt-3 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                                                    {step.error_code}: {step.error_message}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
