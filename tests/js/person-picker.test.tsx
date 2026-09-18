import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { PersonMultiPicker, PersonPicker } from '@/Components/UI/PersonPicker';
import type { UserSummary } from '@/types/tickets';

/**
 * The picker is the only place in Ticktz that decides what a list of people
 * looks like, and the only one that can be wrong about who is on it. What
 * matters here is not that it renders: it is that it asks the server the right
 * question, that the keyboard can reach every answer, and that a search which
 * failed does not look like a search that found nobody.
 *
 * `useTranslations` reads the dictionary Inertia shares on every page, so in a
 * unit test the keys come back as themselves — which also means an assertion
 * fails if somebody renders a raw key by accident.
 */
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            translations: {
                'common.people.search': 'Search by name or e-mail',
                'common.people.searching': 'Searching…',
                'common.people.no_matches': 'Nobody matches :term.',
                'common.people.failed': 'That search could not be run. Try again in a moment.',
                'common.people.nobody': 'Nobody',
                'common.people.clear': 'Clear the selection',
                'common.people.remove': 'Remove :name',
                'common.people.result_count': ':count found',
                'common.actions.edit': 'Edit',
            },
            locale: 'en',
        },
    }),
}));

const ada: UserSummary = {
    id: 1,
    name: 'Ada Lovelace',
    email: 'ada@example.org',
    initials: 'AL',
    avatar_color: '#123456',
};

const alan: UserSummary = {
    id: 2,
    name: 'Alan Turing',
    email: 'alan@example.org',
    initials: 'AT',
    avatar_color: '#654321',
};

/** Every URL the component asked for, in order. */
let asked: string[] = [];

function answerWith(people: UserSummary[] | 'refused') {
    asked = [];

    vi.stubGlobal(
        'fetch',
        vi.fn((url: string) => {
            asked.push(url);

            if (people === 'refused') {
                return Promise.resolve({ ok: false, status: 403, json: () => Promise.resolve({}) });
            }

            return Promise.resolve({ ok: true, json: () => Promise.resolve({ people }) });
        }),
    );
}

beforeEach(() => answerWith([ada, alan]));
afterEach(() => vi.unstubAllGlobals());

describe('PersonPicker', () => {
    it('opens with names in it, without anybody having to type', async () => {
        // A desk with five agents must not make somebody type to see five
        // names. The list this replaced was already on the screen; losing that
        // would be a step backwards dressed up as an improvement.
        render(<PersonPicker value={null} onChange={() => {}} query={{ scope: 'agent' }} />);

        await userEvent.click(screen.getByRole('combobox'));

        expect(await screen.findByText('Ada Lovelace')).toBeTruthy();
        expect(screen.getByText('Alan Turing')).toBeTruthy();
    });

    it('asks the server the question the page is actually asking', async () => {
        render(
            <PersonPicker
                value={null}
                onChange={() => {}}
                query={{ scope: 'assignee', ticket: 'SUP-42', teamId: 7 }}
            />,
        );

        await userEvent.click(screen.getByRole('combobox'));
        await waitFor(() => expect(asked.length).toBeGreaterThan(0));

        expect(asked[0]).toContain('scope=assignee');
        expect(asked[0]).toContain('ticket=SUP-42');
        expect(asked[0]).toContain('team_id=7');
    });

    it('leaves out a filter nobody set, rather than sending it empty', async () => {
        // `team_id=` reads as "the team whose id is nothing" to a server that
        // is looking for a number, and an empty queue filter would narrow the
        // list to nobody.
        render(<PersonPicker value={null} onChange={() => {}} query={{ scope: 'assignee', teamId: null }} />);

        await userEvent.click(screen.getByRole('combobox'));
        await waitFor(() => expect(asked.length).toBeGreaterThan(0));

        expect(asked[0]).not.toContain('team_id');
    });

    it('can be driven entirely from the keyboard', async () => {
        const chosen: UserSummary[] = [];

        render(
            <PersonPicker value={null} onChange={(person) => person && chosen.push(person)} query={{ scope: 'agent' }} />,
        );

        const box = screen.getByRole('combobox');

        await userEvent.click(box);
        await screen.findByText('Ada Lovelace');

        // Down once moves off the first option onto the second.
        await userEvent.keyboard('{ArrowDown}{Enter}');

        expect(chosen).toEqual([alan]);
    });

    it('tells a screen reader which option the keyboard is on', async () => {
        render(<PersonPicker value={null} onChange={() => {}} query={{ scope: 'agent' }} />);

        const box = screen.getByRole('combobox');

        await userEvent.click(box);
        await screen.findByText('Ada Lovelace');

        const first = box.getAttribute('aria-activedescendant');
        expect(first).toBeTruthy();

        await userEvent.keyboard('{ArrowDown}');

        expect(box.getAttribute('aria-activedescendant')).not.toBe(first);
    });

    it('wraps around rather than stopping at the end', async () => {
        render(<PersonPicker value={null} onChange={() => {}} query={{ scope: 'agent' }} />);

        const box = screen.getByRole('combobox');

        await userEvent.click(box);
        await screen.findByText('Ada Lovelace');

        const first = box.getAttribute('aria-activedescendant');

        // Two people, so two downs is back where it started. A cursor that
        // stops dead at the end makes the last option feel broken.
        await userEvent.keyboard('{ArrowDown}{ArrowDown}');

        expect(box.getAttribute('aria-activedescendant')).toBe(first);
    });

    it('says a search failed rather than showing an empty list', async () => {
        // The failure this exists to prevent: a 403 or a dropped connection
        // renders as "nobody matches", somebody believes it, and the person
        // they were looking for is presumed not to work here.
        answerWith('refused');

        render(<PersonPicker value={null} onChange={() => {}} query={{ scope: 'agent' }} />);

        await userEvent.click(screen.getByRole('combobox'));

        // Twice on the page on purpose: once drawn in the list, once in the
        // status region that a screen reader reads. The list's copy is hidden
        // from assistive technology so it is announced once.
        const list = await screen.findByRole('listbox');

        expect(
            await within(list).findByText('That search could not be run. Try again in a moment.'),
        ).toBeTruthy();
        expect(within(list).queryByText(/Nobody matches/)).toBeNull();
        expect(screen.getByRole('status').textContent).toBe(
            'That search could not be run. Try again in a moment.',
        );
    });

    it('says so when the search genuinely found nobody', async () => {
        answerWith([]);

        render(<PersonPicker value={null} onChange={() => {}} query={{ scope: 'agent' }} />);

        await userEvent.click(screen.getByRole('combobox'));
        await userEvent.keyboard('zwart');

        const list = await screen.findByRole('listbox');

        expect(await within(list).findByText('Nobody matches zwart.')).toBeTruthy();
        expect(screen.getByRole('status').textContent).toBe('Nobody matches zwart.');
    });

    it('shows who is chosen instead of a box to search in', async () => {
        render(<PersonPicker value={ada} onChange={() => {}} query={{ scope: 'agent' }} allowNobody />);

        expect(screen.getByText('Ada Lovelace')).toBeTruthy();
        expect(screen.queryByRole('combobox')).toBeNull();
        expect(screen.getByLabelText('Clear the selection')).toBeTruthy();
    });

    it('lets a chosen person be taken back out', async () => {
        let value: UserSummary | null = ada;

        render(<PersonPicker value={value} onChange={(person) => (value = person)} query={{ scope: 'agent' }} allowNobody />);

        await userEvent.click(screen.getByLabelText('Clear the selection'));

        expect(value).toBeNull();
    });

    it('offers nothing to clear when there is nothing to choose', async () => {
        render(<PersonPicker value={ada} onChange={() => {}} query={{ scope: 'agent' }} disabled />);

        expect(screen.queryByLabelText('Clear the selection')).toBeNull();
        expect(screen.queryByLabelText('Edit')).toBeNull();
    });
});

describe('PersonMultiPicker', () => {
    it('does not offer somebody who has already been chosen', async () => {
        render(<PersonMultiPicker value={[ada]} onChange={() => {}} query={{ scope: 'user' }} />);

        await userEvent.click(screen.getByRole('combobox'));

        expect(await screen.findByText('Alan Turing')).toBeTruthy();

        // Ada appears once — as a chip — and not again in the list below it.
        expect(screen.getAllByText('Ada Lovelace')).toHaveLength(1);
    });

    it('adds to the selection rather than replacing it', async () => {
        let value: UserSummary[] = [ada];

        render(<PersonMultiPicker value={value} onChange={(people) => (value = people)} query={{ scope: 'user' }} />);

        await userEvent.click(screen.getByRole('combobox'));
        await userEvent.click(await screen.findByText('Alan Turing'));

        expect(value.map((person) => person.name)).toEqual(['Ada Lovelace', 'Alan Turing']);
    });

    it('removes the one asked for and nobody else', async () => {
        let value: UserSummary[] = [ada, alan];

        render(<PersonMultiPicker value={value} onChange={(people) => (value = people)} query={{ scope: 'user' }} />);

        await userEvent.click(screen.getByLabelText('Remove Ada Lovelace'));

        expect(value).toEqual([alan]);
    });
});
