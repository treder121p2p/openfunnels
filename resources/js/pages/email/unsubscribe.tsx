import { Head, router } from '@inertiajs/react';
import { CheckCircle2, MailX } from 'lucide-react';

export default function Unsubscribe({ email, unsubscribed }: { email: string; unsubscribed: boolean }) {
    return (
        <>
            <Head title="Email preferences" />
            <main className="grid min-h-screen place-items-center bg-background p-6 text-foreground">
                <section className="w-full max-w-md rounded-2xl border border-border bg-card p-8 text-center shadow-xl">
                    {unsubscribed ? (
                        <CheckCircle2 className="mx-auto h-12 w-12 text-emerald-500" />
                    ) : (
                        <MailX className="mx-auto h-12 w-12 text-primary" />
                    )}
                    <h1 className="mt-5 text-2xl font-bold">{unsubscribed ? 'You are unsubscribed' : 'Stop marketing email?'}</h1>
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                        {unsubscribed
                            ? `${email} will no longer receive marketing automation email from this sender.`
                            : `Confirm that ${email} should no longer receive marketing automation email from this sender.`}
                    </p>
                    {!unsubscribed && (
                        <button
                            type="button"
                            onClick={() => router.post(window.location.href)}
                            className="mt-6 w-full rounded-lg bg-primary px-4 py-2.5 font-semibold text-primary-foreground"
                        >
                            Unsubscribe
                        </button>
                    )}
                </section>
            </main>
        </>
    );
}
