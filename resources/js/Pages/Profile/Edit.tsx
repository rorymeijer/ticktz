import AppLayout from '@/Layouts/AppLayout';
import { useTranslations } from '@/hooks/useTranslations';

import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ directoryManaged = false }: { directoryManaged?: boolean }) {
    const { t } = useTranslations();

    return (
        <AppLayout title={t('profile.title')}>
            <div className="mx-auto grid max-w-2xl gap-6">
                <UpdateProfileInformationForm directoryManaged={directoryManaged} />
                {directoryManaged ? null : <UpdatePasswordForm />}
            </div>
        </AppLayout>
    );
}
