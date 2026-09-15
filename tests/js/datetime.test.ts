import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { formatDuration, relativeTime } from '@/lib/datetime';

const t = (key: string, replacements: Record<string, string | number> = {}) =>
    `${key}:${replacements.count ?? ''}`;

describe('relativeTime', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-05-01T12:00:00Z'));
    });

    afterEach(() => vi.useRealTimers());

    it('renders an em dash for a missing value', () => {
        expect(relativeTime(null, t)).toBe('—');
    });

    it('reports seconds as "just now"', () => {
        expect(relativeTime('2026-05-01T11:59:30Z', t)).toBe('common.time.just_now:');
    });

    it('switches to minutes, hours and days', () => {
        expect(relativeTime('2026-05-01T11:30:00Z', t)).toBe('common.time.minutes_ago:30');
        expect(relativeTime('2026-05-01T09:00:00Z', t)).toBe('common.time.hours_ago:3');
        expect(relativeTime('2026-04-28T12:00:00Z', t)).toBe('common.time.days_ago:3');
    });
});

describe('formatDuration', () => {
    it('renders minutes, hours and days compactly', () => {
        expect(formatDuration(45 * 60)).toBe('45m');
        expect(formatDuration(2 * 3600 + 30 * 60)).toBe('2h 30m');
        expect(formatDuration(3 * 86400 + 4 * 3600)).toBe('3d 4h');
    });

    it('renders an overrun without a sign — the caller labels it', () => {
        expect(formatDuration(-90 * 60)).toBe('1h 30m');
    });
});
