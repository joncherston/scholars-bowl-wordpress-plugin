// Browser checks and screenshots for 1.1.0 on a local site (development only).
//
//   PW=/path/to/node_modules/playwright WP="/path/to/wp --path=..." BASE=http://localhost:8089 \
//   SHOTS=/tmp/plugin-screens AXE=/path/to/axe-core/axe.min.js node dev/browser-1.1.0.cjs
//
// WP is a command that runs wp-cli against the site (used to make roster links and change dates).
// Needs dev/seed.php data and an admin user admin / dev-admin-pass.
const { chromium } = require(process.env.PW || 'playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://localhost:8089';
const SHOTS = process.env.SHOTS || '/tmp/plugin-screens';
const WP = process.env.WP || 'wp';
const AXE = process.env.AXE || '';
const ADMIN_PASS = process.env.ADMIN_PASS || 'dev-admin-pass';
fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0;
let fail = 0;
function check(cond, label, detail) {
  if (cond) { pass++; console.log('  ok    ' + label); } else { fail++; console.log('  FAIL  ' + label + (detail ? '  -- ' + detail : '')); }
}
const wp = (args) => execSync(WP + ' ' + args, { encoding: 'utf8' }).trim();
const wpEval = (php) => wp("eval '" + php.replace(/'/g, "'\\''") + "'");

const viewports = {
  desktop: { viewport: { width: 1280, height: 900 } },
  phone: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
};

async function axe(page, label) {
  if (!AXE) return;
  await page.addScriptTag({ path: AXE });
  const result = await page.evaluate(async () => {
    const r = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, target: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) }));
  });
  check(result.length === 0, `axe WCAG 2.1 AA: ${label}`, JSON.stringify(result));
}

(async () => {
  const browser = await chromium.launch();
  const rosterLink = wpEval('echo SBR_Roster::url(SBR_Roster::issue_token(40, "browser-test"));');
  const payToken = wpEval('echo $GLOBALS["sbr_instance"]->generate_payment_token(44, 73);');

  for (const [name, opts] of Object.entries(viewports)) {
    console.log(`\n== ${name}`);
    const ctx = await browser.newContext(opts);
    await ctx.route(/js\.stripe\.com/, (r) => r.abort());
    const page = await ctx.newPage();
    const errors = [];
    // js.stripe.com is blocked in these tests, so the balance-payment page's "Stripe is not defined" is expected.
    page.on('pageerror', (e) => { if (!/Stripe is not defined/.test(e.message)) errors.push(e.message); });

    await page.goto(BASE + '/about/');
    check((await page.evaluate(() => getComputedStyle(document.body).animationName)) === 'none', `${name}: no page fade-in on body`);
    check((await page.evaluate(() => getComputedStyle(document.body).fontFamily)).startsWith('Mulish'), `${name}: body text uses Mulish`);
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: path.join(SHOTS, `theme-page-${name}.png`), fullPage: true });
    if (name === 'desktop') await axe(page, 'theme page');

    await page.goto(BASE + '/register/');
    await page.evaluate(() => document.fonts.ready);
    check((await page.evaluate(() => getComputedStyle(document.querySelector('.sbr-page-title')).fontFamily)).startsWith('"Libre Franklin"'), `${name}: headings use Libre Franklin`);
    await page.screenshot({ path: path.join(SHOTS, `register-${name}.png`), fullPage: true });
    if (name === 'desktop') await axe(page, 'registration form');

    // empty submit: error summary, focus, aria-invalid
    await page.click('#submit-registration');
    const focusedId = await page.evaluate(() => document.activeElement && document.activeElement.id);
    check(focusedId === 'form-messages', `${name}: errors summary takes focus`, focusedId);
    check((await page.getAttribute('#form-messages', 'role')) === 'alert', `${name}: errors summary is role=alert`);
    check((await page.getAttribute('#school_name', 'aria-invalid')) === 'true', `${name}: invalid field marked aria-invalid`);
    await page.screenshot({ path: path.join(SHOTS, `register-errors-${name}.png`), fullPage: false });
    await page.click('#form-messages a[href="#school_name"]');
    check((await page.evaluate(() => document.activeElement.id)) === 'school_name', `${name}: error link moves focus to the field`);
    await page.screenshot({ path: path.join(SHOTS, `register-focus-${name}.png`), fullPage: false });

    await page.goto(rosterLink);
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: path.join(SHOTS, `roster-${name}.png`), fullPage: true });
    if (name === 'desktop') await axe(page, 'roster page');

    await page.goto(BASE + '/complete-payment/?token=' + payToken);
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: path.join(SHOTS, `payment-${name}.png`), fullPage: true });

    check(errors.length === 0, `${name}: no JavaScript errors`, errors.join(' | '));
    await ctx.close();
  }

  // ---- Desktop flows
  console.log('\n== flows (desktop)');
  const ctx = await browser.newContext(viewports.desktop);
  await ctx.route(/js\.stripe\.com/, (r) => r.abort());
  const page = await ctx.newPage();

  async function register(school, names) {
    wpEval('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}scholars_bowl_spam_log");');
    await page.goto(BASE + '/register/');
    await page.fill('#school_name', school);
    await page.fill('#contact_name', 'Dana Fictional');
    await page.fill('#contact_email', 'dana@' + school.toLowerCase().replace(/[^a-z]/g, '') + '.example');
    await page.fill('#contact_phone', '555-010-0199');
    await page.selectOption('#grade_level', '3A');
    await page.selectOption('#competing_students', '4');
    await page.selectOption('#coaches', '3');
    await page.selectOption('#additional_students', '1');
    const visibleRows = await page.locator('.sbr-roster-section .sbr-student-row[data-row]:not([hidden])').count();
    for (let i = 0; i < names.length; i++) {
      await page.fill(`#sbr_students_${i}_first_name`, names[i][0]);
      await page.fill(`#sbr_students_${i}_last_name`, names[i][1]);
      if (names[i][2]) await page.selectOption(`#sbr_students_${i}_grade`, names[i][2]);
    }
    await page.check('input[name=payment_method][value=check]');
    await page.check('input[name=payment_option][value=full]');
    await page.waitForFunction(() => document.querySelector('#total-amount').textContent !== '0.00');
    const shownTotal = await page.textContent('#total-amount');
    const [res] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('sbr_submit_registration')),
      page.click('#submit-registration'),
    ]);
    const body = await res.json();
    await page.waitForSelector('#form-messages.success-message');
    return { visibleRows, shownTotal, body };
  }

  const a = await register('Browser Roster High', [['Mia', 'Chen', '10'], ['Noah', 'Diaz', '12'], ['Owen', 'Fox', '']]);
  check(a.visibleRows === 4, 'form shows one student row per competing student (4)', String(a.visibleRows));
  check(a.body.success, 'registration with three students submitted', JSON.stringify(a.body));
  await page.screenshot({ path: path.join(SHOTS, 'register-success-desktop.png'), fullPage: false });
  const b = await register('Browser No Roster High', []);
  check(b.body.success, 'registration without students submitted');
  check(a.shownTotal === b.shownTotal, `the form shows the same total with and without names ($${a.shownTotal})`);
  const stored = JSON.parse(wpEval(`global $wpdb; echo wp_json_encode($wpdb->get_results("SELECT id, total_amount, deposit_amount, balance_due FROM {$wpdb->prefix}scholars_bowl_registrations WHERE id IN (${a.body.data.registration_id}, ${b.body.data.registration_id}) ORDER BY id", ARRAY_A));`));
  check(stored.length === 2 && stored[0].total_amount === stored[1].total_amount && stored[0].balance_due === stored[1].balance_due && Number(stored[0].total_amount) === Number(a.shownTotal),
    'stored totals are identical with and without names, and match the form', JSON.stringify(stored));
  const nStudents = wpEval(`echo count(SBR_Roster::get_students(${a.body.data.registration_id}));`);
  check(nStudents === '3', 'three students stored for the first registration', nStudents);

  // roster page: edit and save in the browser
  await page.goto(rosterLink);
  await page.fill('#students_0_first_name', 'Rosa');
  await page.fill('#students_0_last_name', 'Grant');
  await page.selectOption('#students_0_grade', '9');
  await page.click('button[type=submit]');
  await page.waitForSelector('#sbr-roster-saved');
  check((await page.evaluate(() => document.activeElement.id)) === 'sbr-roster-saved', 'roster saved message takes focus');
  await page.screenshot({ path: path.join(SHOTS, 'roster-saved-desktop.png'), fullPage: true });
  await page.fill('#students_1_first_name', 'OnlyFirst');
  await page.click('button[type=submit]');
  await page.waitForSelector('#sbr-roster-errors');
  check((await page.evaluate(() => document.activeElement.id)) === 'sbr-roster-errors', 'roster errors take focus');
  await page.screenshot({ path: path.join(SHOTS, 'roster-errors-desktop.png'), fullPage: true });

  // locked roster
  wpEval('global $wpdb; $wpdb->update($wpdb->prefix . "scholars_bowl_events", array("roster_deadline" => "2026-10-01 12:00:00"), array("id" => 2));');
  for (const [name, opts] of Object.entries(viewports)) {
    const c = await browser.newContext(opts);
    const p = await c.newPage();
    await p.goto(rosterLink);
    await p.evaluate(() => document.fonts.ready);
    check((await p.locator('text=Changes are made at check-in').count()) === 1, `${name}: locked roster says changes are made at check-in`);
    await p.screenshot({ path: path.join(SHOTS, `roster-locked-${name}.png`), fullPage: true });
    await c.close();
  }
  wpEval('global $wpdb; $wpdb->update($wpdb->prefix . "scholars_bowl_events", array("roster_deadline" => null), array("id" => 2));');

  // admin
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', ADMIN_PASS);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  await page.goto(BASE + '/wp-admin/admin.php?page=scholars-bowl-add-event&event_id=2');
  const divCount = await page.locator('#sbr-divisions > .sbr-division').count();
  check(divCount === 3, 'event editor lists 3 divisions', String(divCount));
  await page.click('#sbr-add-division');
  check((await page.locator('#sbr-divisions > .sbr-division').count()) === 4, 'Add a division adds a row');
  await page.locator('#sbr-divisions > .sbr-division').nth(3).locator('.sbr-division-format').selectOption('round_robin');
  check(!(await page.locator('#sbr-divisions > .sbr-division').nth(3).locator('.sbr-bracket-only').first().isVisible()), 'bracket fields hide for round robin');
  page.once('dialog', (d) => d.accept());
  await page.locator('#sbr-divisions > .sbr-division').nth(3).locator('.sbr-remove-division').click();
  check((await page.locator('#sbr-divisions > .sbr-division').count()) === 3, 'Remove deletes the row');
  await page.locator('#sbr-divisions > .sbr-division').nth(0).locator('input[value="1A"]').check();
  await page.locator('#sbr-divisions > .sbr-division').nth(1).locator('input[value="1A"]').uncheck();
  check((await page.textContent('#sbr-session-classes [data-session="morning"]')).trim() === 'Private, 1A, 2A, 3A', 'derived session list updates live');
  await page.locator('#sbr-divisions > .sbr-division').nth(0).locator('input[value="1A"]').uncheck();
  await page.locator('#sbr-divisions > .sbr-division').nth(1).locator('input[value="1A"]').check();
  await page.locator('#sbr-divisions').scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(SHOTS, 'admin-event-divisions-desktop.png'), fullPage: true });
  await page.goto(BASE + '/wp-admin/admin.php?page=scholars-bowl-registrations&event_filter=2');
  await page.screenshot({ path: path.join(SHOTS, 'admin-registrations-desktop.png'), fullPage: false });
  await page.locator('tr', { hasText: 'Cedar Hollow School' }).locator('text=View').click();
  await page.waitForSelector('.sbr-admin-roster');
  await page.locator('.sbr-admin-roster').scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(SHOTS, 'admin-roster-dialog-desktop.png'), fullPage: false });
  await page.goto(BASE + '/wp-admin/admin.php?page=scholars-bowl-api');
  await page.screenshot({ path: path.join(SHOTS, 'admin-api-access-desktop.png'), fullPage: true });

  await browser.close();
  console.log(`\n${fail ? 'FAILED' : 'PASSED'}: ${pass} passed, ${fail} failed. Screenshots in ${SHOTS}`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('BROWSER TEST CRASHED', e); process.exit(1); });
