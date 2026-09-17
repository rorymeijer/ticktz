/**
 * Inline SVG icon set (Heroicons-style outlines, hand-inlined).
 *
 * Shipping ~20 paths ourselves keeps an icon dependency — and its tree-shaking
 * footguns — out of the bundle entirely.
 */
import type { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

function Icon({ children, ...props }: IconProps) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className="h-4 w-4"
            {...props}
        >
            {children}
        </svg>
    );
}

export const IconDashboard = (p: IconProps) => (
    <Icon {...p}>
        <path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 9h8V3h-8z" />
    </Icon>
);
export const IconTicket = (p: IconProps) => (
    <Icon {...p}>
        <path d="M4 9V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4Z" />
        <path d="M13 5v2M13 11v2M13 17v2" />
    </Icon>
);
export const IconInbox = (p: IconProps) => (
    <Icon {...p}>
        <path d="M3 12h4l2 3h6l2-3h4" />
        <path d="M5 5h14l2 7v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5Z" />
    </Icon>
);
export const IconBook = (p: IconProps) => (
    <Icon {...p}>
        <path d="M4 5a2 2 0 0 1 2-2h12v16H6a2 2 0 0 0-2 2Z" />
        <path d="M18 19v2H6" />
    </Icon>
);
export const IconServer = (p: IconProps) => (
    <Icon {...p}>
        <rect x="3" y="4" width="18" height="6" rx="2" />
        <rect x="3" y="14" width="18" height="6" rx="2" />
        <path d="M7 7h.01M7 17h.01" />
    </Icon>
);
export const IconCheckCircle = (p: IconProps) => (
    <Icon {...p}>
        <circle cx="12" cy="12" r="9" />
        <path d="m8.5 12.5 2.5 2.5 4.5-5" />
    </Icon>
);
export const IconChart = (p: IconProps) => (
    <Icon {...p}>
        <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
    </Icon>
);
export const IconCog = (p: IconProps) => (
    <Icon {...p}>
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z" />
    </Icon>
);
export const IconUsers = (p: IconProps) => (
    <Icon {...p}>
        <path d="M16 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1" />
        <circle cx="9" cy="7" r="3.2" />
        <path d="M22 20v-1a4 4 0 0 0-3-3.9M16.5 4.2a3.2 3.2 0 0 1 0 6.2" />
    </Icon>
);
export const IconPlus = (p: IconProps) => (
    <Icon {...p}>
        <path d="M12 5v14M5 12h14" />
    </Icon>
);
export const IconSearch = (p: IconProps) => (
    <Icon {...p}>
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.5-3.5" />
    </Icon>
);
export const IconChevronDown = (p: IconProps) => (
    <Icon {...p}>
        <path d="m6 9 6 6 6-6" />
    </Icon>
);
export const IconChevronRight = (p: IconProps) => (
    <Icon {...p}>
        <path d="m9 6 6 6-6 6" />
    </Icon>
);
export const IconMenu = (p: IconProps) => (
    <Icon {...p}>
        <path d="M4 6h16M4 12h16M4 18h16" />
    </Icon>
);
export const IconX = (p: IconProps) => (
    <Icon {...p}>
        <path d="M6 6l12 12M18 6 6 18" />
    </Icon>
);
export const IconGlobe = (p: IconProps) => (
    <Icon {...p}>
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18" />
    </Icon>
);
export const IconClock = (p: IconProps) => (
    <Icon {...p}>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
    </Icon>
);
export const IconBolt = (p: IconProps) => (
    <Icon {...p}>
        <path d="M13 3 4 14h7l-1 7 9-11h-7z" />
    </Icon>
);
export const IconPaperclip = (p: IconProps) => (
    <Icon {...p}>
        <path d="M21 12.5 12.5 21a5 5 0 0 1-7-7l8-8a3.5 3.5 0 0 1 5 5l-8 8a2 2 0 0 1-3-3l7.5-7.5" />
    </Icon>
);
export const IconLock = (p: IconProps) => (
    <Icon {...p}>
        <rect x="4" y="10" width="16" height="11" rx="2" />
        <path d="M8 10V7a4 4 0 0 1 8 0v3" />
    </Icon>
);
export const IconMail = (p: IconProps) => (
    <Icon {...p}>
        <rect x="3" y="5" width="18" height="14" rx="2" />
        <path d="m3 7 9 6 9-6" />
    </Icon>
);
export const IconTag = (p: IconProps) => (
    <Icon {...p}>
        <path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9z" />
        <circle cx="7.5" cy="7.5" r="1.2" />
    </Icon>
);
export const IconLink = (p: IconProps) => (
    <Icon {...p}>
        <path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1" />
        <path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1" />
    </Icon>
);
export const IconDownload = (p: IconProps) => (
    <Icon {...p}>
        <path d="M12 3v12m0 0 4-4m-4 4-4-4" />
        <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
    </Icon>
);
export const IconTrash = (p: IconProps) => (
    <Icon {...p}>
        <path d="M4 7h16M10 11v6M14 11v6" />
        <path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13M9 7V4h6v3" />
    </Icon>
);
export const IconAlert = (p: IconProps) => (
    <Icon {...p}>
        <path d="M12 3 2 20h20z" />
        <path d="M12 9v5M12 17h.01" />
    </Icon>
);

/*
 * Editor toolbar. These are never the accessible name of anything — every
 * toolbar button carries its own aria-label — so they stay aria-hidden like
 * the rest of the set.
 */
export const IconBold = (p: IconProps) => (
    <Icon {...p}>
        <path d="M7 5h5.5a3.5 3.5 0 0 1 0 7H7zM7 12h6.5a3.5 3.5 0 0 1 0 7H7z" />
    </Icon>
);
export const IconItalic = (p: IconProps) => (
    <Icon {...p}>
        <path d="M11 5h7M6 19h7M14.5 5 9.5 19" />
    </Icon>
);
export const IconUnderline = (p: IconProps) => (
    <Icon {...p}>
        <path d="M7 4v6a5 5 0 0 0 10 0V4M5 20h14" />
    </Icon>
);
export const IconStrike = (p: IconProps) => (
    <Icon {...p}>
        <path d="M4 12h16" />
        <path d="M8 8.5A3.5 3.5 0 0 1 11.5 5h1.8a3.4 3.4 0 0 1 3.2 2M16.5 15a3.5 3.5 0 0 1-3.5 4h-1.8A3.5 3.5 0 0 1 7.7 16" />
    </Icon>
);
export const IconCode = (p: IconProps) => (
    <Icon {...p}>
        <path d="m9 8-4 4 4 4M15 8l4 4-4 4" />
    </Icon>
);
export const IconCodeBlock = (p: IconProps) => (
    <Icon {...p}>
        <rect x="3" y="5" width="18" height="14" rx="2" />
        <path d="m10 10-2 2 2 2M14 10l2 2-2 2" />
    </Icon>
);
export const IconBulletList = (p: IconProps) => (
    <Icon {...p}>
        <path d="M9 6h11M9 12h11M9 18h11" />
        <path d="M4.5 6h.01M4.5 12h.01M4.5 18h.01" strokeWidth={2.5} />
    </Icon>
);
export const IconOrderedList = (p: IconProps) => (
    <Icon {...p}>
        <path d="M9 6h11M9 12h11M9 18h11" />
        <path d="M3.5 4.5h1v3.5M3.5 8h2" />
        <path d="M3.5 11.5h2l-2 3h2" />
        <path d="M3.5 17h2l-1.2 1.2H5" />
    </Icon>
);
export const IconQuote = (p: IconProps) => (
    <Icon {...p}>
        <path d="M6 8h4v4a4 4 0 0 1-4 4M14 8h4v4a4 4 0 0 1-4 4" />
    </Icon>
);
export const IconHeading = (p: IconProps) => (
    <Icon {...p}>
        <path d="M6 5v14M14 5v14M6 12h8" />
    </Icon>
);
export const IconRule = (p: IconProps) => (
    <Icon {...p}>
        <path d="M4 12h16" />
        <path d="M7 7h10M7 17h10" strokeOpacity={0.4} />
    </Icon>
);
export const IconUnlink = (p: IconProps) => (
    <Icon {...p}>
        <path d="M10 13a4 4 0 0 0 5.66 0l2-2a4 4 0 1 0-5.66-5.66l-1 1" />
        <path d="M14 11a4 4 0 0 0-5.66 0l-2 2A4 4 0 0 0 12 18.66l1-1" />
        <path d="m4 4 16 16" />
    </Icon>
);
export const IconUndo = (p: IconProps) => (
    <Icon {...p}>
        <path d="M9 14 4 9l5-5" />
        <path d="M4 9h10a6 6 0 0 1 0 12H9" />
    </Icon>
);
export const IconRedo = (p: IconProps) => (
    <Icon {...p}>
        <path d="m15 14 5-5-5-5" />
        <path d="M20 9H10a6 6 0 0 0 0 12h5" />
    </Icon>
);

export const IconImage = (p: IconProps) => (
    <Icon {...p}>
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <circle cx="8.5" cy="9.5" r="1.5" />
        <path d="m4 17 4.5-4.5a2 2 0 0 1 2.8 0L16 17M14 15l1.6-1.6a2 2 0 0 1 2.8 0L20 15" />
    </Icon>
);
