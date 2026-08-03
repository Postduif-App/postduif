import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
    /**
     * Widen the column for a page that shows a list rather than a form.
     *
     * The same escape hatch the settings shell has, and for the same reason: a
     * form reads better narrow, but a decrypted API key wrapped over four lines
     * in a 384px card is unusable.
     */
    wide?: boolean;
};
