import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Download, Filter, Mail, Phone, Plus, RotateCcw, Search, UserRound, Users } from 'lucide-react';
import { type FormEvent, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Contacts',
        href: '/contacts',
    },
];

interface ContactItem {
    id: number;
    email: string;
    name: string | null;
    phone: string | null;
    status: string;
    source: string;
    funnel: {
        id: number;
        name: string;
        slug: string;
    } | null;
    submission_count: number;
    last_submitted_at: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedContacts {
    data: ContactItem[];
    links: PaginationLink[];
    total: number;
    from: number | null;
    to: number | null;
}

interface ContactFilters {
    search: string;
    status: string;
    funnel_id: string;
    date_from: string;
    date_to: string;
}

interface ContactsPageProps {
    contacts: PaginatedContacts;
    stats: {
        total_contacts: number;
        new_contacts: number;
        captured_today: number;
    };
    filters: ContactFilters;
    filterOptions: {
        statuses: string[];
        funnels: Array<{
            id: number;
            name: string;
        }>;
    };
}

const statusStyles: Record<string, string> = {
    new: 'border-sky-500/20 bg-sky-500/10 text-sky-500',
    contacted: 'border-violet-500/20 bg-violet-500/10 text-violet-500',
    qualified: 'border-amber-500/20 bg-amber-500/10 text-amber-500',
    won: 'border-emerald-500/20 bg-emerald-500/10 text-emerald-500',
    lost: 'border-red-500/20 bg-red-500/10 text-red-500',
};

function titleCase(value: string): string {
    return value.replace(/[_-]+/g, ' ').replace(/^./, (character) => character.toUpperCase());
}

function paginationLabel(label: string): string {
    if (label.includes('Previous')) return 'Previous';
    if (label.includes('Next')) return 'Next';

    return label;
}

export default function Contacts({ contacts, stats, filters, filterOptions }: ContactsPageProps) {
    const [form, setForm] = useState<ContactFilters>(filters);
    const hasFilters = Object.values(filters).some(Boolean);

    const updateFilter = (key: keyof ContactFilters, value: string) => {
        setForm((current) => ({ ...current, [key]: value }));
    };

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(
            route('contacts.index'),
            {
                search: form.search,
                status: form.status,
                funnel_id: form.funnel_id,
                date_from: form.date_from,
                date_to: form.date_to,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    const clearFilters = () => {
        const emptyFilters: ContactFilters = {
            search: '',
            status: '',
            funnel_id: '',
            date_from: '',
            date_to: '',
        };

        setForm(emptyFilters);
        router.get(route('contacts.index'), {}, { preserveState: false, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Contacts" />
            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h1 className="text-2xl font-bold text-foreground">Contacts</h1>
                        <p className="text-muted-foreground">Find, segment, and export leads captured from your funnels</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {contacts.total > 0 ? (
                            <a
                                href={route('contacts.export', {
                                    search: filters.search,
                                    status: filters.status,
                                    funnel_id: filters.funnel_id,
                                    date_from: filters.date_from,
                                    date_to: filters.date_to,
                                })}
                                className="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                            >
                                <Download className="h-4 w-4" />
                                Export CSV
                            </a>
                        ) : (
                            <span
                                className="inline-flex cursor-not-allowed items-center justify-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-medium text-foreground opacity-50"
                                aria-disabled="true"
                            >
                                <Download className="h-4 w-4" />
                                Export CSV
                            </span>
                        )}
                        <Link
                            href={route('funnel-editor')}
                            className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                        >
                            <Plus className="h-4 w-4" />
                            Create Capture Funnel
                        </Link>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-xl border border-border bg-card p-5">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">Total Contacts</p>
                                <p className="text-2xl font-bold text-foreground">{stats.total_contacts}</p>
                            </div>
                            <Users className="h-7 w-7 text-chart-1" />
                        </div>
                    </div>
                    <div className="rounded-xl border border-border bg-card p-5">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">New Contacts</p>
                                <p className="text-2xl font-bold text-foreground">{stats.new_contacts}</p>
                            </div>
                            <UserRound className="h-7 w-7 text-chart-2" />
                        </div>
                    </div>
                    <div className="rounded-xl border border-border bg-card p-5">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">Captured Today</p>
                                <p className="text-2xl font-bold text-foreground">{stats.captured_today}</p>
                            </div>
                            <Mail className="h-7 w-7 text-chart-3" />
                        </div>
                    </div>
                </div>

                <form onSubmit={applyFilters} className="rounded-xl border border-border bg-card p-4">
                    <div className="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
                        <Filter className="h-4 w-4" />
                        Filter contacts
                    </div>
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.5fr)_1fr_1fr_1fr_1fr_auto]">
                        <label className="relative">
                            <span className="sr-only">Search contacts</span>
                            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                value={form.search}
                                onChange={(event) => updateFilter('search', event.target.value)}
                                placeholder="Name, email, or phone"
                                className="h-10 w-full rounded-lg border border-border bg-background pr-3 pl-9 text-sm text-foreground placeholder:text-muted-foreground"
                            />
                        </label>
                        <label>
                            <span className="sr-only">Filter by status</span>
                            <select
                                value={form.status}
                                onChange={(event) => updateFilter('status', event.target.value)}
                                className="h-10 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground"
                            >
                                <option value="">All statuses</option>
                                {filterOptions.statuses.map((status) => (
                                    <option key={status} value={status}>
                                        {titleCase(status)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            <span className="sr-only">Filter by funnel</span>
                            <select
                                value={form.funnel_id}
                                onChange={(event) => updateFilter('funnel_id', event.target.value)}
                                className="h-10 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground"
                            >
                                <option value="">All funnels</option>
                                {filterOptions.funnels.map((funnel) => (
                                    <option key={funnel.id} value={funnel.id}>
                                        {funnel.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            <span className="sr-only">Captured from date</span>
                            <input
                                type="date"
                                value={form.date_from}
                                onChange={(event) => updateFilter('date_from', event.target.value)}
                                className="h-10 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground"
                            />
                        </label>
                        <label>
                            <span className="sr-only">Captured through date</span>
                            <input
                                type="date"
                                value={form.date_to}
                                onChange={(event) => updateFilter('date_to', event.target.value)}
                                className="h-10 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground"
                            />
                        </label>
                        <button
                            type="submit"
                            className="h-10 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                        >
                            Apply
                        </button>
                    </div>
                    {(hasFilters || Object.values(form).some(Boolean)) && (
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="mt-3 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <RotateCcw className="h-3.5 w-3.5" />
                            Clear filters
                        </button>
                    )}
                </form>

                <div className="space-y-3">
                    <div className="text-sm text-muted-foreground">
                        {contacts.total === 0 ? 'No matching contacts' : `Showing ${contacts.from}–${contacts.to} of ${contacts.total} contacts`}
                    </div>

                    <div className="overflow-hidden rounded-xl border border-border bg-card">
                        <div className="overflow-x-auto">
                            <div className="min-w-[760px]">
                                <div className="grid grid-cols-[minmax(220px,1.3fr)_minmax(160px,1fr)_120px_160px] gap-4 border-b border-border px-5 py-3 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    <div>Contact</div>
                                    <div>Funnel</div>
                                    <div>Status</div>
                                    <div>Last Activity</div>
                                </div>

                                {contacts.data.map((contact) => (
                                    <div
                                        key={contact.id}
                                        className="grid grid-cols-[minmax(220px,1.3fr)_minmax(160px,1fr)_120px_160px] items-center gap-4 border-b border-border px-5 py-4 last:border-b-0"
                                    >
                                        <div className="min-w-0">
                                            <Link
                                                href={route('contacts.show', contact.id)}
                                                className="block truncate font-medium text-foreground hover:text-primary"
                                            >
                                                {contact.name || contact.email}
                                            </Link>
                                            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                                <span className="inline-flex min-w-0 items-center gap-1">
                                                    <Mail className="h-3.5 w-3.5 shrink-0" />
                                                    <span className="truncate">{contact.email}</span>
                                                </span>
                                                {contact.phone && (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Phone className="h-3.5 w-3.5" />
                                                        {contact.phone}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="min-w-0 text-sm text-muted-foreground">
                                            {contact.funnel ? (
                                                <Link
                                                    href={route('funnel-editor.edit', contact.funnel.id)}
                                                    className="block truncate text-foreground hover:text-primary"
                                                >
                                                    {contact.funnel.name}
                                                </Link>
                                            ) : (
                                                'Unknown funnel'
                                            )}
                                            <div>
                                                {contact.submission_count} submission{contact.submission_count === 1 ? '' : 's'}
                                            </div>
                                        </div>
                                        <div>
                                            <span
                                                className={`rounded-full border px-2 py-1 text-xs font-medium ${
                                                    statusStyles[contact.status] || 'border-border bg-muted text-muted-foreground'
                                                }`}
                                            >
                                                {titleCase(contact.status)}
                                            </span>
                                        </div>
                                        <div className="text-sm text-muted-foreground">{contact.last_submitted_at || contact.created_at}</div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {contacts.data.length === 0 && (
                            <div className="border-t border-border px-5 py-14 text-center">
                                <Users className="mx-auto mb-4 h-10 w-10 text-muted-foreground/60" />
                                <h3 className="mb-1 font-medium text-foreground">
                                    {hasFilters ? 'No contacts match these filters' : 'No contacts yet'}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {hasFilters
                                        ? 'Clear or adjust the filters to broaden your results.'
                                        : 'Publish a funnel with a form block to start capturing leads.'}
                                </p>
                                {hasFilters && (
                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        className="mt-4 text-sm font-medium text-primary hover:text-primary/80"
                                    >
                                        Clear filters
                                    </button>
                                )}
                            </div>
                        )}
                    </div>

                    {contacts.links.length > 3 && (
                        <nav className="flex flex-wrap items-center justify-center gap-1" aria-label="Contacts pagination">
                            {contacts.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url}
                                        preserveScroll
                                        className={`rounded-md border px-3 py-1.5 text-sm transition-colors ${
                                            link.active
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border bg-card text-muted-foreground hover:bg-muted hover:text-foreground'
                                        }`}
                                    >
                                        {paginationLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span
                                        key={`${link.label}-${index}`}
                                        className="cursor-not-allowed rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground/40"
                                    >
                                        {paginationLabel(link.label)}
                                    </span>
                                ),
                            )}
                        </nav>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
