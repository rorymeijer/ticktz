import { Link } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { Card, CardBody, EmptyState, PageHeader } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

interface ChapterSummary {
    slug: string;
    title: string;
    summary: string;
}

/**
 * The table of contents.
 *
 * It lists what this reader can read, which is not the same list for everybody
 * — every chapter gates itself on the permission its subject needs. Somebody
 * who cannot open the automation screen is not shown a chapter about
 * automation rules, because a manual that describes doors you cannot open is a
 * manual that makes people feel locked out rather than helped.
 */
export default function ManualIndex({ chapters }: { chapters: ChapterSummary[] }) {
    const { t } = useTranslations();

    return (
        <AppLayout title={t('common.manual.title')}>
            <PageHeader title={t('common.manual.title')} description={t('common.manual.subtitle')} />

            {chapters.length === 0 ? (
                <EmptyState title={t('common.manual.empty')} />
            ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                    {chapters.map((chapter) => (
                        <Link key={chapter.slug} href={`/manual/${chapter.slug}`} className="block">
                            <Card className="h-full transition hover:border-brand-300">
                                <CardBody>
                                    <h2 className="text-sm font-semibold text-slate-900">{chapter.title}</h2>
                                    <p className="mt-1 text-sm text-slate-600">{chapter.summary}</p>
                                </CardBody>
                            </Card>
                        </Link>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
