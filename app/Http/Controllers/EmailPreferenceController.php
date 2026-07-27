<?php

namespace App\Http\Controllers;

use App\Models\ContactEmailPreference;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailPreferenceController extends Controller
{
    public function show(ContactEmailPreference $preference)
    {
        $preference->load('contact:id,email');

        return Inertia::render('email/unsubscribe', [
            'email' => $this->maskEmail($preference->contact->email),
            'unsubscribed' => $preference->status === 'unsubscribed',
        ]);
    }

    public function update(Request $request, ContactEmailPreference $preference)
    {
        $preference->update([
            'status' => 'unsubscribed',
            'source' => 'unsubscribe_link',
            'unsubscribed_at' => $preference->unsubscribed_at ?? now(),
        ]);

        return back()->with('success', 'You have been unsubscribed from marketing email.');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
