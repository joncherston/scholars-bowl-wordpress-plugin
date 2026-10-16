// Submits the public registration form (pay by check) on the local dev site and reports the result.
// Run from a folder with playwright-core installed:  PW=/path/to/node_modules/playwright-core node dev/form-smoke.cjs
const { chromium } = require(process.env.PW || 'playwright-core');

(async () => {
	const school = `Smoke Test Academy ${Date.now() % 100000}`;
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	page.on('pageerror', (e) => errors.push(e.message));
	await page.goto('http://localhost:8089/register/');
	await page.fill('#school_name', school);
	await page.fill('#contact_name', 'Dana Fictional');
	await page.fill('#contact_email', 'dana@smoke.example');
	await page.fill('#contact_phone', '555-010-0199');
	await page.selectOption('#grade_level', '3A');
	await page.selectOption('#competing_students', '5');
	if (await page.$eval('#coaches', (e) => e.tagName) === 'SELECT') await page.selectOption('#coaches', '1'); else await page.fill('#coaches', '1');
	await page.check('input[name=payment_method][value=check]');
	const [res] = await Promise.all([
		page.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData()?.includes('sbr_submit_registration'), { timeout: 20000 }),
		page.click('button[type=submit], input[type=submit]')
	]);
	const body = await res.text();
	console.log(JSON.stringify({ school, status: res.status(), response: body.slice(0, 300), pageErrors: errors }));
	await browser.close();
})().catch((e) => {
	console.error('SMOKE FAILED', e.message);
	process.exit(1);
});
