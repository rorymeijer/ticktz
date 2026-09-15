import PortalLayout from '@/Layouts/PortalLayout';
import { Card, CardBody, EmptyState } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

export default function PortalIndex({
    branding,
}: {
    branding: { title: string | null; welcome_message: string | null; support_email: string | null };
}) {
    const { t } = useTranslations();

    return (
        <PortalLayout title={branding.title ?? t('nav.portal')}>
            <div className="mb-6">
                <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
                    {t('portal.welcome.title')}
                </h2>
                <p className="mt-1.5 text-sm text-slate-600">
                    {branding.welcome_message || t('portal.welcome.subtitle')}
                </p>
            </div>

            <Card>
                <CardBody>
                    <EmptyState title={t('common.empty.title')} description={t('common.empty.description')} />
                </CardBody>
            </Card>
        </PortalLayout>
    );
}
