import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import { Button, Card, CardBody, CardFooter, CardHeader, Field, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

export default function UpdatePasswordForm() {
    const { t } = useTranslations();
    const { data, setData, put, errors, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card>
            <CardHeader title={t('profile.password.title')} description={t('profile.password.description')} />
            <form onSubmit={submit}>
                <CardBody className="space-y-4">
                    <Field label={t('profile.password.current')} error={errors.current_password} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                type="password"
                                value={data.current_password}
                                autoComplete="current-password"
                                required
                                onChange={(event) => setData('current_password', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('profile.password.new')} error={errors.password} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                type="password"
                                value={data.password}
                                autoComplete="new-password"
                                required
                                onChange={(event) => setData('password', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('profile.password.confirm')} error={errors.password_confirmation} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                type="password"
                                value={data.password_confirmation}
                                autoComplete="new-password"
                                required
                                onChange={(event) => setData('password_confirmation', event.target.value)}
                            />
                        )}
                    </Field>
                </CardBody>
                <CardFooter>
                    {recentlySuccessful ? (
                        <span className="mr-auto text-xs font-medium text-emerald-600">
                            {t('profile.password.saved')}
                        </span>
                    ) : null}
                    <Button type="submit" disabled={processing}>
                        {t('common.actions.save')}
                    </Button>
                </CardFooter>
            </form>
        </Card>
    );
}
