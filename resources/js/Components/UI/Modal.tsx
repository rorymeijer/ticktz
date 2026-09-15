import { Dialog, DialogPanel, DialogTitle, Transition, TransitionChild } from '@headlessui/react';
import { Fragment, type ReactNode } from 'react';

import { cn } from '@/lib/cn';

export function Modal({
    open,
    onClose,
    title,
    description,
    children,
    footer,
    size = 'md',
}: {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    size?: 'sm' | 'md' | 'lg' | 'xl';
}) {
    const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };

    return (
        <Transition show={open} as={Fragment}>
            <Dialog onClose={onClose} className="relative z-50">
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-150"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-100"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-[1px]" aria-hidden="true" />
                </TransitionChild>

                <div className="fixed inset-0 overflow-y-auto p-4 sm:p-6">
                    <div className="flex min-h-full items-center justify-center">
                        <TransitionChild
                            as={Fragment}
                            enter="ease-out duration-150"
                            enterFrom="opacity-0 translate-y-2 sm:scale-95"
                            enterTo="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-100"
                            leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                            leaveTo="opacity-0 translate-y-2 sm:scale-95"
                        >
                            <DialogPanel className={cn('w-full rounded-xl bg-white shadow-popover', widths[size])}>
                                <div className="border-b border-slate-200 px-5 py-4">
                                    <DialogTitle className="text-sm font-semibold text-slate-900">{title}</DialogTitle>
                                    {description ? <p className="mt-0.5 text-xs text-slate-500">{description}</p> : null}
                                </div>
                                <div className="px-5 py-4">{children}</div>
                                {footer ? (
                                    <div className="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50/60 px-5 py-3">
                                        {footer}
                                    </div>
                                ) : null}
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}
