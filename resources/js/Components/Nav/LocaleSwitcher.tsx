import { router } from '@inertiajs/react';

import { Dropdown, DropdownButton, DropdownHeading } from '@/Components/UI';
import { IconChevronDown, IconGlobe } from '@/Components/Icons';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';

/**
 * Switches the UI language. For signed-in users the choice is persisted on
 * their profile; guests get a session-scoped `?lang=` override.
 */
export function LocaleSwitcher({ className }: { className?: string }) {
    const { t, locale, locales } = useTranslations();
    const current = locales.find((option) => option.code === locale);

    const switchTo = (code: string) => {
        const url = new URL(window.location.href);
        url.searchParams.set('lang', code);
        router.visit(url.toString(), { preserveScroll: true, preserveState: false });
    };

    return (
        <Dropdown
            className={className}
            trigger={
                <button
                    type="button"
                    aria-label={t('nav.switch_language')}
                    className={cn(
                        'inline-flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-slate-600',
                        'hover:bg-white hover:text-slate-900',
                    )}
                >
                    <IconGlobe />
                    <span className="hidden sm:inline">{current?.native ?? locale.toUpperCase()}</span>
                    <IconChevronDown className="h-3.5 w-3.5" />
                </button>
            }
        >
            <DropdownHeading>{t('nav.switch_language')}</DropdownHeading>
            {locales.map((option) => (
                <DropdownButton key={option.code} onClick={() => switchTo(option.code)}>
                    <span className={cn('flex-1', option.code === locale && 'font-semibold text-brand-700')}>
                        {option.native}
                    </span>
                    {option.code === locale ? <span aria-hidden="true">✓</span> : null}
                </DropdownButton>
            ))}
        </Dropdown>
    );
}
