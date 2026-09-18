import { Link } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { Card, CardBody, PageHeader } from '@/Components/UI';
import { RichText } from '@/Components/RichText/RichText';
import { useTranslations } from '@/hooks/useTranslations';

interface ChapterSummary {
    slug: string;
    title: string;
    summary: string;
}

export default function ManualShow({
    chapter,
    chapters,
}: {
    chapter: { slug: string; title: string; html: string };
    chapters: ChapterSummary[];
}) {
    const { t } = useTranslations();

    return (
        <AppLayout title={chapter.title}>
            <PageHeader
                title={chapter.title}
                actions={
                    <Link href="/manual" className="text-sm font-medium text-brand-700 hover:text-brand-900">
                        {t('common.manual.back')}
                    </Link>
                }
            />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_16rem]">
                <Card>
                    <CardBody>
                        <RichText html={chapter.html} className="break-words" />
                    </CardBody>
                </Card>

                {/* The rest of the manual stays in view, because the chapter
                    somebody needs is often the one next to the chapter they
                    opened. */}
                <nav aria-label={t('common.manual.contents')}>
                    <Card>
                        <CardBody className="space-y-1">
                            {chapters.map((entry) => (
                                <Link
                                    key={entry.slug}
                                    href={`/manual/${entry.slug}`}
                                    className={
                                        entry.slug === chapter.slug
                                            ? 'block rounded px-2 py-1 text-sm font-semibold text-brand-800'
                                            : 'block rounded px-2 py-1 text-sm text-slate-600 hover:bg-slate-50'
                                    }
                                    aria-current={entry.slug === chapter.slug ? 'page' : undefined}
                                >
                                    {entry.title}
                                </Link>
                            ))}
                        </CardBody>
                    </Card>
                </nav>
            </div>
        </AppLayout>
    );
}
