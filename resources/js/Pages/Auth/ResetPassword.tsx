import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { t } = useTranslations();
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('password.store'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout title={t('auth.reset.title')}>
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('auth.register.email')} error={errors.email} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="email"
                            name="email"
                            value={data.email}
                            autoComplete="username"
                            required
                            onChange={(event) => setData('email', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('auth.register.password')} error={errors.password} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="password"
                            name="password"
                            value={data.password}
                            autoComplete="new-password"
                            autoFocus
                            required
                            onChange={(event) => setData('password', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('auth.register.password_confirmation')} error={errors.password_confirmation} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="password"
                            name="password_confirmation"
                            value={data.password_confirmation}
                            autoComplete="new-password"
                            required
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                        />
                    )}
                </Field>

                <Button type="submit" size="lg" className="w-full" disabled={processing}>
                    {t('auth.reset.submit')}
                </Button>
            </form>
        </AuthLayout>
    );
}
