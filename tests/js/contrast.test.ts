import { describe, expect, it } from 'vitest';

import { blend, contrastRatio, readableInk, tintedChip } from '@/lib/contrast';

describe('contrastRatio', () => {
    it('matches the WCAG reference values', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
        expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
        // Tailwind slate-500 on white — the figure the palette decisions rest on.
        expect(contrastRatio('#64748b', '#ffffff')).toBeCloseTo(4.76, 2);
    });

    it('is symmetric and tolerates shorthand', () => {
        expect(contrastRatio('#fff', '#000')).toBeCloseTo(contrastRatio('#000', '#fff'), 5);
    });

    it('returns 1 rather than throwing on a colour it cannot read', () => {
        expect(contrastRatio('rebeccapurple', '#ffffff')).toBe(1);
        expect(contrastRatio('', '#ffffff')).toBe(1);
    });
});

describe('blend', () => {
    it('composites an alpha over a backdrop', () => {
        expect(blend('#000000', '#ffffff', 0.5)).toBe('#808080');
        expect(blend('#ff0000', '#ffffff', 0)).toBe('#ffffff');
        expect(blend('#ff0000', '#ffffff', 1)).toBe('#ff0000');
    });
});

describe('readableInk', () => {
    it('leaves a colour alone when it already passes', () => {
        // Near-black on white needs no help.
        expect(readableInk('#111111', '#ffffff')).toBe('#111111');
    });

    it('darkens a colour that does not pass, and keeps going until it does', () => {
        const ink = readableInk('#d97706', '#fbf1e6');

        expect(ink).not.toBe('#d97706');
        expect(contrastRatio(ink, '#fbf1e6')).toBeGreaterThanOrEqual(4.5);
    });
});

describe('tintedChip', () => {
    /**
     * The property that matters: whatever colour an administrator picks, the
     * chip is legible. A fixed palette cannot promise this, because the palette
     * belongs to the operator.
     */
    it('produces a readable pair for every colour in the default palettes', () => {
        const colors = [
            // Statuses and priorities from TicketWorkflowSeeder.
            '#2563eb', '#7c3aed', '#d97706', '#ca8a04', '#059669', '#475569',
            '#dc2626', '#ea580c', '#64748b',
            // The failures axe surfaced, plus the extremes.
            '#0891b2', '#ffffff', '#000000', '#ffff00', '#f8fafc',
        ];

        for (const color of colors) {
            const chip = tintedChip(color);

            expect(
                contrastRatio(chip.color, chip.backgroundColor),
                `${color} -> ink ${chip.color} on ${chip.backgroundColor}`,
            ).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('survives a colour it cannot parse without producing an unreadable chip', () => {
        const chip = tintedChip('not-a-colour');

        expect(chip.backgroundColor).toBe('#ffffff');
        expect(contrastRatio(chip.color, chip.backgroundColor)).toBeGreaterThanOrEqual(4.5);
    });
});
