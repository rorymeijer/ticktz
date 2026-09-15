import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import { Button, Card, CardBody, CardFooter, CardHeader, Field, Select, TextInput } from '@/Components/UI';
import { useAuth } from '@/hooks/useAuth';
import { useTranslations } from '@/hooks/useTranslations';

export default function UpdateProfileInformationForm({ directoryManaged }: { directoryManaged: boolean }) {
    const { t, locales } = useTranslations();
    const { user } = useAuth();

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        locale: user?.locale ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        patch(route('profile.update'));
    };

    return (
        <Card>
            <CardHeader title={t('profile.information.title')} description={t('profile.information.description')} />
            <form onSubmit={submit}>
                <CardBody className="space-y-4">
                    {directoryManaged ? (
                        <p className="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800">
                            {t('profile.information.managed_by_directory')}
                        </p>
                    ) : null}

                    <Field label={t('profile.information.name')} error={errors.name} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={data.name}
                                autoComplete="name"
                                disabled={directoryManaged}
                                required
                                onChange={(event) => setData('name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('profile.information.email')} error={errors.email} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                type="email"
                                value={data.email}
                                autoComplete="username"
                                disabled={directoryManaged}
                                required
                                onChange={(event) => setData('email', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label={t('profile.information.locale')}
                        help={t('profile.information.locale_help')}
                        error={errors.locale}
                    >
                        {(props) => (
                            <Select
                                {...props}
                                value={data.locale}
                                onChange={(event) => setData('locale', event.target.value)}
                            >
                                {locales.map((option) => (
                                    <option key={option.code} value={option.code}>
                                        {option.native}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </CardBody>
                <CardFooter>
                    {recentlySuccessful ? (
                        <span className="mr-auto text-xs font-medium text-emerald-600">
                            {t('profile.information.saved')}
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
