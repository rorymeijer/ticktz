import { Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

export default function VerifyEmail({ status }: { status?: string }) {
    const { t } = useTranslations();
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('verification.send'));
    };

    return (
        <AuthLayout
            title={t('auth.verify.title')}
            description={t('auth.verify.subtitle')}
            footer={
                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="font-medium text-brand-600 hover:text-brand-700"
                >
                    {t('auth.logout')}
                </Link>
            }
        >
            {status === 'verification-link-sent' ? (
                <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">
                    {t('auth.verify.sent')}
                </p>
            ) : null}

            <form onSubmit={submit}>
                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    {t('auth.verify.resend')}
                </Button>
            </form>
        </AuthLayout>
    );
}
