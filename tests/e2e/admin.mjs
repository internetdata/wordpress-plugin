// Drives the admin screens in Chromium, as a site owner connecting through Tor would: the self-check,
// Connect with InternetData against the stand-in API, saving settings, country rules, geo redirects
// and the checkout's billing check among them, the Activity tab and its Allow link, and a held order.
// It saves the screenshots the plugin directory shows.
//
//   node tests/e2e/admin.mjs <WordPress container address> <order id> <screenshot directory>
//
// PLAYWRIGHT_MODULE names another copy of playwright (playwright-core will do) and CHROMIUM a browser
// for it to drive, where neither is installed the default way.

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const [address, order, shots] = process.argv.slice(2);
const site = 'http://wp';
const paidBadge = 'Paid plan or trial';
const failures = [];

function check(name, ok) {
    console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${name}`);
    if (!ok) {
        failures.push(name);
    }
}

const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM || undefined,
    args: [`--host-resolver-rules=MAP wp ${address}`],
});
try {
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        extraHTTPHeaders: { 'X-Forwarded-For': '185.220.101.1' },
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));

    await page.goto(`${site}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'correct-horse');
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);

    await page.goto(`${site}/wp-admin/options-general.php?page=internetdata`);
    check('the self-check names the address', (await page.textContent('#inetd-connection')).includes('185.220.101.1'));
    check('  ... and says it is Tor', (await page.textContent('#inetd-connection')).includes('Tor'));
    check('an unconnected site offers Connect', await page.isVisible('#inetd-connect'));
    check('VPNs and the proxies need a paid plan or the trial', (await page.textContent('.inetd-signals')).includes(paidBadge));

    const popup = context.waitForEvent('page');
    await page.click('#inetd-connect');
    const tab = await popup;
    const approval = await tab.waitForRequest(/user_code=/);
    await page.waitForFunction(() => document.getElementById('inetd-connect-status').textContent.includes('WXYZ-1234'));
    check('Connect opens the approval page with the code', approval.url().includes('user_code=WXYZ-1234'));
    check('  ... tagged as the plugin\'s', approval.url().includes('utm_source=wordpress'));
    await tab.close();
    await page.waitForSelector('text=Connected, on the Max plan.', { timeout: 30_000 });
    check('once approved the site is connected', true);
    check('  ... and no signal needs a better plan', !(await page.textContent('.inetd-signals')).includes(paidBadge));

    await page.selectOption('.inetd-countries-mode', 'allow');
    await page.fill('.inetd-signals .inetd-countries', 'de, at, ch');
    await page.selectOption('#inetd-login', 'block');
    await page.selectOption('#inetd-comment', 'hold');
    await page.selectOption('#inetd-checkout', 'hold');
    await page.selectOption('#inetd-pages', 'log');
    check('an area that is on shows its options', await page.isVisible('tr[data-area="comment"] .inetd-own'));
    await page.check('tr[data-area="comment"] .inetd-own');
    check('  ... and its own signals once asked for', await page.isVisible('tr[data-area="comment"] .inetd-own-signals'));
    await page.check('tr[data-area="comment"] .inetd-own-signals input[value="hosting"]');
    check('checkout offers its billing country check', await page.isVisible('input[name$="[checkout_billing]"]'));
    await page.check('input[name$="[checkout_billing]"]');
    check('pages offer geo redirects', await page.isVisible('#inetd-geo'));
    await page.fill('#inetd-geo', 'FR https://example.fr/\nnonsense');
    await page.fill('#inetd-allowlist', '203.0.113.7 # the office');
    await page.click('#submit');
    // A warning takes the place of WordPress's own Settings saved.
    await page.waitForSelector('text=These geo redirect lines');
    check('the settings save', await page.inputValue('#inetd-login') === 'block');
    check('  ... an area\'s own signals with them', await page.isChecked('tr[data-area="comment"] .inetd-own'));
    check('  ... and the site\'s signals stay its own', await page.isChecked('.inetd-signals input[value="tor"]'));
    check('  ... the countries allowed, as codes', await page.inputValue('.inetd-signals .inetd-countries') === 'DE, AT, CH');
    check('  ... allowed rather than blocked', await page.inputValue('.inetd-countries-mode') === 'allow');
    check('  ... the billing country check', await page.isChecked('input[name$="[checkout_billing]"]'));
    check('  ... and the geo redirects', (await page.inputValue('#inetd-geo')).includes('FR https://example.fr/'));
    check('  ... naming the line that is no geo redirect', (await page.textContent('#wpbody')).includes(': nonsense'));
    await page.fill('#inetd-geo', 'FR https://example.fr/');
    await page.click('#submit');
    await page.waitForSelector('text=Settings saved.');
    // A taller window, never fullPage with a clip, which drew this page's content squashed into it.
    await page.setViewportSize({ width: 1280, height: 1500 });
    await page.screenshot({ path: `${shots}/screenshot-1.png` });
    await page.setViewportSize({ width: 1280, height: 900 });

    await page.goto(`${site}/wp-admin/options-general.php?page=internetdata&tab=activity`);
    check('the Activity tab lists who was caught', await page.isVisible('table.wp-list-table tbody tr td code'));
    await page.screenshot({ path: `${shots}/screenshot-2.png` });
    await page.click('table.wp-list-table tbody tr:first-child .row-actions a');
    await page.waitForSelector('text=is on the allowlist');
    check('Allow this address puts it on the allowlist', true);
    await page.goto(`${site}/wp-admin/options-general.php?page=internetdata`);
    check('  ... where the settings show it', (await page.inputValue('#inetd-allowlist')).includes('# the office'));

    if (order) {
        await page.goto(`${site}/wp-admin/admin.php?page=wc-orders&action=edit&id=${order}`);
        if (!(await page.isVisible('.order_notes'))) {
            await page.goto(`${site}/wp-admin/post.php?post=${order}&action=edit`);
        }
        check('a held order shows its note', (await page.textContent('.order_notes')).includes('InternetData flagged this order'));
        await page.locator('#woocommerce-order-notes').screenshot({ path: `${shots}/screenshot-3.png` });
    }
    check('no script error on any screen', errors.length === 0);
    if (errors.length > 0) {
        console.log(errors.join('\n'));
    }
} finally {
    await browser.close();
}
process.exit(failures.length === 0 ? 0 : 1);
