import AppLayout from '@/Layouts/AppLayout';
import { Card, CardBody, EmptyState } from '@/Components/UI';
import { useAuth } from '@/hooks/useAuth';
import { useTranslations } from '@/hooks/useTranslations';

export default function Dashboard() {
    const { t } = useTranslations();
    const { user } = useAuth();

    return (
        <AppLayout title={t('dashboard.title')}>
            <div className="mb-6">
                <h2 className="text-xl font-semibold tracking-tight text-slate-900">
                    {t('dashboard.greeting', { name: user?.name ?? '' })}
                </h2>
                <p className="mt-1 text-sm text-slate-500">{t('dashboard.subtitle')}</p>
            </div>

            <Card>
                <CardBody>
                    <EmptyState title={t('dashboard.no_data')} />
                </CardBody>
            </Card>
        </AppLayout>
    );
}
