<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $contacts = $this->filteredContacts($request, $filters)
            ->with('funnel:id,name,slug')
            ->latest('last_submitted_at')
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Contact $contact) => [
                'id' => $contact->id,
                'email' => $contact->email,
                'name' => $contact->name,
                'phone' => $contact->phone,
                'status' => $contact->status,
                'source' => $contact->source,
                'funnel' => $contact->funnel ? [
                    'id' => $contact->funnel->id,
                    'name' => $contact->funnel->name,
                    'slug' => $contact->funnel->slug,
                ] : null,
                'submission_count' => (int) data_get($contact->metadata, 'submission_count', 1),
                'last_submitted_at' => $contact->last_submitted_at?->format('M j, Y g:i A'),
                'created_at' => $contact->created_at->format('M j, Y'),
            ]);

        return Inertia::render('contacts', [
            'contacts' => $contacts,
            'stats' => [
                'total_contacts' => $request->user()->contacts()->count(),
                'new_contacts' => $request->user()->contacts()->where('status', 'new')->count(),
                'captured_today' => $request->user()->contacts()->whereDate('created_at', today())->count(),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'funnel_id' => isset($filters['funnel_id']) ? (string) $filters['funnel_id'] : '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],
            'filterOptions' => [
                'statuses' => ['new', 'contacted', 'qualified', 'won', 'lost'],
                'funnels' => $request->user()
                    ->funnels()
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $contacts = $this->filteredContacts($request, $filters)
            ->with('funnel:id,name')
            ->latest('last_submitted_at')
            ->latest();

        return response()->streamDownload(function () use ($contacts): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Name',
                'Email',
                'Phone',
                'Status',
                'Source',
                'Funnel',
                'Submission Count',
                'Last Activity',
                'Created At',
            ], escape: '');

            foreach ($contacts->lazy(500) as $contact) {
                $row = [
                    $contact->name,
                    $contact->email,
                    $contact->phone,
                    $contact->status,
                    $contact->source,
                    $contact->funnel?->name,
                    (int) data_get($contact->metadata, 'submission_count', 1),
                    $contact->last_submitted_at?->toIso8601String(),
                    $contact->created_at->toIso8601String(),
                ];

                fputcsv(
                    $output,
                    array_map(fn ($value) => $this->sanitizeCsvCell($value), $row),
                    escape: '',
                );
            }

            fclose($output);
        }, 'openfunnels-contacts-'.today()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(Request $request, Contact $contact)
    {
        abort_unless($contact->user_id === $request->user()->id, 403);

        $contact->load([
            'funnel:id,name,slug',
            'submissions' => fn ($query) => $query->with('funnel:id,name,slug')->latest(),
            'opportunities' => fn ($query) => $query
                ->with(['pipeline:id,name,currency', 'stage:id,name'])
                ->latest(),
        ]);

        return Inertia::render('contact-detail', [
            'contact' => [
                'id' => $contact->id,
                'email' => $contact->email,
                'name' => $contact->name,
                'phone' => $contact->phone,
                'status' => $contact->status,
                'source' => $contact->source,
                'tags' => $contact->tags ?? [],
                'metadata' => $contact->metadata ?? [],
                'notes' => data_get($contact->metadata, 'notes', []),
                'funnel' => $contact->funnel ? [
                    'id' => $contact->funnel->id,
                    'name' => $contact->funnel->name,
                    'slug' => $contact->funnel->slug,
                ] : null,
                'ip_address' => $contact->ip_address,
                'user_agent' => $contact->user_agent,
                'last_submitted_at' => $contact->last_submitted_at?->format('M j, Y g:i A'),
                'created_at' => $contact->created_at->format('M j, Y g:i A'),
                'updated_at' => $contact->updated_at->format('M j, Y g:i A'),
                'submissions' => $contact->submissions->map(fn ($submission) => [
                    'id' => $submission->id,
                    'form_id' => $submission->form_id,
                    'fields' => $submission->fields ?? [],
                    'source' => $submission->source,
                    'url' => $submission->url,
                    'ip_address' => $submission->ip_address,
                    'user_agent' => $submission->user_agent,
                    'created_at' => $submission->created_at->format('M j, Y g:i A'),
                    'funnel' => $submission->funnel ? [
                        'id' => $submission->funnel->id,
                        'name' => $submission->funnel->name,
                        'slug' => $submission->funnel->slug,
                    ] : null,
                ]),
                'opportunities' => $contact->opportunities->map(fn ($opportunity) => [
                    'id' => $opportunity->id,
                    'title' => $opportunity->title,
                    'value_cents' => $opportunity->value_cents,
                    'status' => $opportunity->status,
                    'expected_close_date' => $opportunity->expected_close_date?->format('Y-m-d'),
                    'pipeline' => [
                        'id' => $opportunity->pipeline->id,
                        'name' => $opportunity->pipeline->name,
                        'currency' => $opportunity->pipeline->currency,
                    ],
                    'stage' => [
                        'id' => $opportunity->stage->id,
                        'name' => $opportunity->stage->name,
                    ],
                ]),
            ],
            'statusOptions' => ['new', 'contacted', 'qualified', 'won', 'lost'],
        ]);
    }

    public function update(Request $request, Contact $contact)
    {
        abort_unless($contact->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:new,contacted,qualified,won,lost'],
        ]);

        $contact->update($validated);

        return back()->with('success', 'Contact updated.');
    }

    public function storeNote(Request $request, Contact $contact)
    {
        abort_unless($contact->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $metadata = $contact->metadata ?? [];
        $notes = data_get($metadata, 'notes', []);
        $notes[] = [
            'id' => (string) str()->uuid(),
            'body' => $validated['note'],
            'created_at' => now()->toISOString(),
        ];

        data_set($metadata, 'notes', $notes);
        $contact->update(['metadata' => $metadata]);

        return back()->with('success', 'Note added.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:new,contacted,qualified,won,lost'],
            'funnel_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        if (isset($filters['search'])) {
            $filters['search'] = trim($filters['search']);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredContacts(Request $request, array $filters): Builder
    {
        return $request->user()
            ->contacts()
            ->getQuery()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['funnel_id'] ?? null, fn (Builder $query, int $funnelId) => $query->where('funnel_id', $funnelId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date));
    }

    private function sanitizeCsvCell(mixed $value): string
    {
        $cell = (string) ($value ?? '');

        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $cell) === 1) {
            return "'".$cell;
        }

        return $cell;
    }
}
