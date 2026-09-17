import { Menu, MenuButton, MenuItem, MenuItems, Transition } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { Fragment, type ElementType, type ReactNode } from 'react';

import { cn } from '@/lib/cn';

export function Dropdown({
    trigger,
    children,
    align = 'right',
    className,
}: {
    trigger: ReactNode;
    children: ReactNode;
    align?: 'left' | 'right';
    className?: string;
}) {
    return (
        <Menu as="div" className={cn('relative inline-block text-left', className)}>
            <MenuButton as={Fragment}>{trigger}</MenuButton>
            <Transition
                as={Fragment}
                enter="transition ease-out duration-100"
                enterFrom="transform opacity-0 scale-95"
                enterTo="transform opacity-100 scale-100"
                leave="transition ease-in duration-75"
                leaveFrom="transform opacity-100 scale-100"
                leaveTo="transform opacity-0 scale-95"
            >
                <MenuItems
                    className={cn(
                        'absolute z-40 mt-1 min-w-48 origin-top overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-popover focus:outline-none',
                        align === 'right' ? 'right-0' : 'left-0',
                    )}
                >
                    {children}
                </MenuItems>
            </Transition>
        </Menu>
    );
}

const itemClasses = 'flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700';

export function DropdownLink({
    href,
    children,
    method,
    as,
    danger,
}: {
    href: string;
    children: ReactNode;
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    as?: ElementType;
    danger?: boolean;
}) {
    return (
        <MenuItem>
            {({ focus }) => (
                <Link
                    href={href}
                    method={method}
                    as={as}
                    className={cn(itemClasses, focus && 'bg-slate-50', danger && 'text-red-600')}
                >
                    {children}
                </Link>
            )}
        </MenuItem>
    );
}

export function DropdownButton({
    onClick,
    children,
    danger,
    disabled,
}: {
    onClick: () => void;
    children: ReactNode;
    danger?: boolean;
    disabled?: boolean;
}) {
    return (
        <MenuItem disabled={disabled}>
            {({ focus }) => (
                <button
                    type="button"
                    onClick={onClick}
                    disabled={disabled}
                    className={cn(
                        itemClasses,
                        focus && 'bg-slate-50',
                        danger && 'text-red-600',
                        disabled && 'cursor-not-allowed opacity-50',
                    )}
                >
                    {children}
                </button>
            )}
        </MenuItem>
    );
}

export function DropdownDivider() {
    return <div className="my-1 h-px bg-slate-100" />;
}

export function DropdownHeading({ children }: { children: ReactNode }) {
    return <p className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{children}</p>;
}
