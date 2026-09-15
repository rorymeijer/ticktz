/**
 * Captures the screenshots used in docs/screenshots.md.
 *
 * Usage:
 *   php artisan migrate:fresh --force && php artisan ticktz:demo
 *   npm run build
 *   php artisan serve --port=8123 &
 *   node scripts/screenshots.mjs [--base http://127.0.0.1:8123] [--only slug,slug]
 *
 * Every shot signs in through the real login form, so the screenshots always
 * reflect what a user with that role actually sees.
 */
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const args = process.argv.slice(2);
const option = (name, fallback) => {
    const index = args.indexOf(`--${name}`);
    return index === -1 ? fallback : args[index + 1];
};

const BASE = option('base', 'http://127.0.0.1:8123').replace(/\/$/, '');
const ONLY = option('only', null);
const OUT = option('out', 'docs/screenshots');
const EXECUTABLE = process.env.PLAYWRIGHT_CHROMIUM ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const ACCOUNTS = {
    admin: { email: 'rianne@ticktz.test', password: 'ticktz-demo' },
    agent: { email: 'joost@ticktz.test', password: 'ticktz-demo' },
    requester: { email: 'm.visser@zandvliet.test', password: 'ticktz-demo' },
    // The requester whose ticket is sitting in an approval.
    'requester-handhaving': { email: 's.mulder@zandvliet.test', password: 'ticktz-demo' },
    // The requester whose laptop, dock and monitor are in the register.
    'requester-bouzid': { email: 'a.bouzid@zandvliet.test', password: 'ticktz-demo' },
};

const SHOTS = [
    { slug: '01-landing', path: '/', as: null },
    { slug: '02-login', path: '/login', as: null },
    { slug: '03-login-nl', path: '/login?lang=nl', as: null },
    { slug: '10-admin-overview', path: '/admin?lang=en', as: 'admin' },
    { slug: '11-admin-users', path: '/admin/users?lang=en', as: 'admin' },
    { slug: '12-admin-user-form', path: '/admin/users/create?lang=en', as: 'admin', fullPage: true },
    { slug: '13-admin-roles', path: '/admin/roles?lang=en', as: 'admin' },
    { slug: '15-admin-teams', path: '/admin/teams?lang=en', as: 'admin' },
    { slug: '16-admin-organizations', path: '/admin/organizations?lang=en', as: 'admin' },
    { slug: '17-admin-directories', path: '/admin/directories?lang=en', as: 'admin' },
    { slug: '18-admin-settings', path: '/admin/settings?lang=en', as: 'admin', fullPage: true },
    { slug: '19-admin-audit-log', path: '/admin/audit-log?lang=en', as: 'admin' },
    { slug: '1a-admin-email', path: '/admin/email?lang=en', as: 'admin', fullPage: true },
    { slug: '1c-admin-sla', path: '/admin/sla?lang=en', as: 'admin', fullPage: true },
    { slug: '1e-admin-automation', path: '/admin/automation?lang=en', as: 'admin', fullPage: true },
    { slug: '1f-admin-automation-nl', path: '/admin/automation?lang=nl', as: 'admin' },
    { slug: '1d-admin-sla-nl', path: '/admin/sla?lang=nl', as: 'admin' },
    { slug: '1b-admin-email-nl', path: '/admin/email?lang=nl', as: 'admin' },
    { slug: '20-agent-dashboard', path: '/dashboard?lang=en', as: 'agent' },
    { slug: '21-agent-tickets', path: '/agent/tickets?lang=en', as: 'agent' },
    { slug: '21b-agent-tickets-breached', path: '/agent/tickets?sla=breached&lang=en', as: 'agent' },
    { slug: '22-agent-tickets-queue', path: '/agent/tickets?queue_slug=unassigned&lang=en', as: 'agent' },
    { slug: '23-agent-queues', path: '/agent/queues?lang=en', as: 'agent' },
    { slug: '24-agent-ticket-detail', path: '/agent/tickets/SUP-1?lang=en', as: 'agent', fullPage: true },
    { slug: '25-agent-ticket-create', path: '/agent/tickets/create?lang=en', as: 'agent' },
    { slug: '26-agent-tickets-nl', path: '/agent/tickets?lang=nl', as: 'agent' },
    { slug: '14-admin-service-desk', path: '/admin/service-desk?lang=en', as: 'admin', fullPage: true },
    { slug: '14b-admin-workflow', path: '/admin/workflows/1/edit?lang=en', as: 'admin', fullPage: true },
    { slug: '14c-admin-queue', path: '/admin/queues/1/edit?lang=en', as: 'admin', fullPage: true },
    { slug: '30-portal', path: '/portal?lang=en', as: 'requester' },
    { slug: '32-portal-form', path: '/portal/new/new-colleague?lang=en', as: 'requester', fullPage: true },
    { slug: '33-portal-requests', path: '/portal/requests?lang=en', as: 'requester' },
    { slug: '34-portal-request-detail', path: '/portal/requests/SUP-1?lang=en', as: 'requester', fullPage: true },
    { slug: '35-portal-nl', path: '/portal?lang=nl', as: 'requester' },
    { slug: '14d-admin-request-types', path: '/admin/request-types?lang=en', as: 'admin' },
    { slug: '14e-admin-request-type-form', path: '/admin/request-types/1/edit?lang=en', as: 'admin', fullPage: true },
    { slug: '14f-admin-custom-fields', path: '/admin/custom-fields?lang=en', as: 'admin' },
    { slug: '31-profile-nl', path: '/profile?lang=nl', as: 'agent' },
    { slug: '50-portal-kb', path: '/portal/kb?lang=en', as: 'requester' },
    { slug: '51-portal-kb-article', path: '/portal/kb/papierstoring-verhelpen-bij-de-canon-printers?lang=en', as: 'requester', fullPage: true },
    { slug: '52-portal-kb-search', path: '/portal/kb?q=printer&lang=en', as: 'requester' },
    { slug: '53-agent-kb', path: '/agent/kb?lang=en', as: 'agent' },
    { slug: '54-admin-kb', path: '/admin/kb?lang=en', as: 'admin', fullPage: true },
    { slug: '55-admin-kb-edit', path: '/admin/kb/papierstoring-verhelpen-bij-de-canon-printers/edit?lang=en', as: 'admin', fullPage: true },
    { slug: '56-admin-kb-nl', path: '/admin/kb?lang=nl', as: 'admin' },
    { slug: '60-approvals-inbox', path: '/approvals?lang=en', as: 'admin' },
    { slug: '61-admin-approvals', path: '/admin/approvals?lang=en', as: 'admin', fullPage: true },
    { slug: '62-admin-approvals-nl', path: '/admin/approvals?lang=nl', as: 'admin' },
    { slug: '63-agent-ticket-approval', path: '/agent/tickets/SUP-6?lang=en', as: 'agent', fullPage: true },
    { slug: '64-portal-approval', path: '/portal/requests/SUP-6?lang=nl', as: 'requester-handhaving', fullPage: true },
    { slug: '70-agent-assets', path: '/agent/assets?lang=en', as: 'admin' },
    { slug: '71-agent-asset-detail', path: '/agent/assets/LAP-0042?lang=en', as: 'admin', fullPage: true },
    { slug: '72-agent-asset-server', path: '/agent/assets/SRV-0002?lang=en', as: 'admin', fullPage: true },
    { slug: '73-admin-asset-types', path: '/admin/asset-types?lang=en', as: 'admin' },
    { slug: '74-admin-asset-import', path: '/admin/assets/import?lang=en', as: 'admin' },
    { slug: '75-portal-equipment', path: '/portal/equipment?lang=nl', as: 'requester-bouzid' },
    { slug: '76-agent-assets-nl', path: '/agent/assets?lang=nl', as: 'admin' },
    { slug: '40-mobile-portal', path: '/portal?lang=en', as: 'requester', width: 400, height: 780 },
    { slug: '41-mobile-tickets', path: '/agent/tickets?lang=en', as: 'agent', width: 400, height: 780 },
    { slug: '42-mobile-kb', path: '/portal/kb?lang=en', as: 'requester', width: 400, height: 780 },
];

async function signIn(context, account) {
    const page = await context.newPage();
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.waitForSelector('input[name="email"]', { timeout: 15000 });
    await page.fill('input[name="email"]', account.email);
    await page.fill('input[name="password"]', account.password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        page.click('button[type="submit"]'),
    ]);
    await page.close();
}

const patterns = ONLY ? ONLY.split(',').map((part) => part.trim()).filter(Boolean) : [];
const shots = patterns.length
    ? SHOTS.filter((shot) => patterns.some((pattern) => shot.slug.includes(pattern)))
    : SHOTS;

if (shots.length === 0) {
    console.error(`No shot matches --only ${ONLY}. Known slugs:\n  ${SHOTS.map((shot) => shot.slug).join('\n  ')}`);
    process.exit(1);
}

await mkdir(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: EXECUTABLE, args: ['--no-sandbox'] });
const contexts = new Map();

async function contextFor(role, width, height) {
    const key = `${role ?? 'guest'}:${width}x${height}`;

    if (!contexts.has(key)) {
        const context = await browser.newContext({
            viewport: { width, height },
            deviceScaleFactor: 2,
            locale: 'en-GB',
            timezoneId: 'Europe/Amsterdam',
            colorScheme: 'light',
            reducedMotion: 'reduce',
        });

        if (role) {
            await signIn(context, ACCOUNTS[role]);
        }

        contexts.set(key, context);
    }

    return contexts.get(key);
}

let failures = 0;

for (const shot of shots) {
    const width = shot.width ?? 1440;
    const height = shot.height ?? 900;

    try {
        const context = await contextFor(shot.as, width, height);
        const page = await context.newPage();

        await page.goto(`${BASE}${shot.path}`, { waitUntil: 'networkidle' });
        // Inertia mounts after the module graph resolves, which can be after
        // the network goes idle — wait for the React tree, not for the socket.
        await page.waitForSelector('#app > *', { state: 'attached', timeout: 15000 });

        if (shot.prepare) {
            await shot.prepare(page);
            await page.waitForLoadState('networkidle');
        }

        // Let fonts settle so text never renders mid-swap.
        await page.evaluate(() => document.fonts?.ready);
        await page.waitForTimeout(400);

        await page.screenshot({ path: `${OUT}/${shot.slug}.png`, fullPage: shot.fullPage ?? false });
        await page.close();

        console.log(`ok   ${shot.slug} — ${shot.path}`);
    } catch (error) {
        failures += 1;
        console.error(`FAIL ${shot.slug} — ${error.message}`);
    }
}

for (const context of contexts.values()) {
    await context.close();
}

await browser.close();

process.exit(failures === 0 ? 0 : 1);
