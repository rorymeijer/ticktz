/**
 * Runs axe-core over every shell of the application and fails on any violation.
 *
 * Usage:
 *   php artisan migrate:fresh --force && php artisan ticktz:demo
 *   npm run build
 *   php artisan serve --port=8123 &
 *   node scripts/accessibility.mjs [--base http://127.0.0.1:8123]
 *
 * Each page is visited as a real signed-in user, because half of what there is
 * to check only exists behind a login — an audit of the marketing page proves
 * nothing about the agent console.
 *
 * The budget is zero. A tolerated violation is a violation nobody ever fixes,
 * and the ones this found the first time it ran (an unnamed account menu on
 * sixteen pages, half the avatar palette too light for white initials, label
 * chips at 2.85:1) were all invisible to everyone looking at the screens.
 *
 * It cannot see everything: axe checks the rendered DOM, so it says nothing
 * about keyboard order, focus visibility or whether a label makes sense. Those
 * stay a human job. What it does catch, it catches every time.
 */
import { existsSync } from 'node:fs';

import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';

const args = process.argv.slice(2);
const option = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index === -1 ? fallback : args[index + 1];
};

const BASE = option('base', 'http://127.0.0.1:8123').replace(/\/$/, '');
// Use the pinned browser when it is actually on disk (the dev container ships
// one), and otherwise let Playwright resolve the copy it installed itself.
// Handing it a path that does not exist fails with a worse message than simply
// not knowing.
const PINNED = process.env.PLAYWRIGHT_CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const EXECUTABLE = existsSync(PINNED) ? PINNED : undefined;

const ACCOUNTS = {
    admin: { email: 'rianne@ticktz.test', password: 'ticktz-demo' },
    agent: { email: 'joost@ticktz.test', password: 'ticktz-demo' },
    requester: { email: 'm.visser@zandvliet.test', password: 'ticktz-demo' },
};

/** One page per surface, as the role that actually works in it. */
const PAGES = [
    ['/', null],
    ['/login', null],
    ['/portal', 'requester'],
    ['/portal/kb', 'requester'],
    ['/portal/requests', 'requester'],
    ['/dashboard', 'agent'],
    ['/agent/tickets', 'agent'],
    ['/agent/queues', 'agent'],
    ['/agent/assets', 'agent'],
    ['/agent/kb', 'agent'],
    ['/approvals', 'agent'],
    ['/profile', 'agent'],
    ['/reports', 'admin'],
    ['/admin', 'admin'],
    ['/admin/users', 'admin'],
    ['/admin/service-desk', 'admin'],
    ['/admin/webhooks', 'admin'],
    ['/settings/api-tokens', 'admin'],

    /*
     * The pages with an editor on them.
     *
     * Worth naming separately because a list page proves nothing about the
     * widget on the form behind it: the rich text toolbar is a custom control
     * with its own roles, pressed states and accessible names, and it is
     * exactly the kind of thing that passes review and fails a screen reader.
     * Keyed on the demo seed, which is what this script runs against.
     */
    ['/agent/tickets/create', 'agent'],
    ['/agent/tickets/SUP-1', 'agent'],
    ['/portal/new/application-access', 'requester'],
    // A knowledge base article routes on its slug, not its id. This one comes
    // from the demo seed, which is what this script is documented to run
    // against; if the seed changes, the run reports the page as failed to load
    // rather than quietly checking one page fewer.
    ['/admin/kb/wachtwoord-opnieuw-instellen/edit', 'admin'],
];

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const browser = await chromium.launch({ executablePath: EXECUTABLE, args: ['--no-sandbox'] });
const contexts = new Map();

async function contextFor(role) {
    if (!contexts.has(role)) {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-GB' });

        if (role) {
            const page = await context.newPage();
            await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
            await page.fill('input[name="email"]', ACCOUNTS[role].email);
            await page.fill('input[name="password"]', ACCOUNTS[role].password);
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle' }),
                page.click('button[type="submit"]'),
            ]);
            await page.close();
        }

        contexts.set(role, context);
    }

    return contexts.get(role);
}

const findings = new Map();
let errors = 0;

for (const [path, role] of PAGES) {
    const page = await (await contextFor(role)).newPage();

    try {
        await page.goto(`${BASE}${path}?lang=en`, { waitUntil: 'networkidle' });
        // Inertia mounts after the module graph resolves, which can be later
        // than the network going idle.
        await page.waitForSelector('#app > *', { state: 'attached', timeout: 15000 });

        const { violations } = await new AxeBuilder({ page }).withTags(TAGS).analyze();

        for (const violation of violations) {
            if (!findings.has(violation.id)) {
                findings.set(violation.id, { impact: violation.impact, help: violation.help, where: [] });
            }

            for (const node of violation.nodes.slice(0, 3)) {
                findings.get(violation.id).where.push(`${path}  ${node.target.join(' ')}`);
            }
        }

        console.log(`${violations.length === 0 ? 'ok  ' : 'FAIL'} ${path}`);
    } catch (error) {
        errors += 1;
        console.error(`ERROR ${path} — ${error.message}`);
    }

    await page.close();
}

for (const context of contexts.values()) {
    await context.close();
}

await browser.close();

if (findings.size > 0) {
    console.error('\nViolations:');

    for (const [id, finding] of findings) {
        console.error(`\n  [${finding.impact}] ${id} — ${finding.help}`);
        for (const where of finding.where.slice(0, 6)) console.error(`      ${where}`);
    }
}

console.log(`\n${findings.size} rule(s) violated across ${PAGES.length} pages, ${errors} page(s) failed to load.`);

process.exit(findings.size === 0 && errors === 0 ? 0 : 1);
