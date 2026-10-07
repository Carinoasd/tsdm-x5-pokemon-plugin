const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const {chromium, expect: baseExpect} = require('@playwright/test');
const {startFixture} = require('./fixture.cjs');

const expect = baseExpect.configure({timeout: 10000});

async function run() {
  const fixture = await startFixture();
  let browser;
  let context;
  let page;
  const errors = [];
  const artifacts = path.join(__dirname, 'artifacts');
  try {
    browser = await chromium.launch({headless: true});
    context = await browser.newContext({viewport: {width: 1440, height: 1100}});
    await context.tracing.start({screenshots: true, snapshots: true});
    page = await context.newPage();
    page.on('pageerror', error => errors.push(String(error)));
    await page.goto(fixture.url);
    await expect(page.getByRole('button', {name: '全局配置', exact: true})).toBeVisible();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    console.log('PASS: compiled admin WASM starts in Chromium');

    await page.getByTestId('nav-effects').click();
    await page.getByTestId('effect-create').click();
    await expect(page.getByTestId('effect-save')).toBeDisabled();
    await page.getByTestId('effect-code').fill('browser_attack_drop');
    await page.getByTestId('effect-hook-on_hit').check();
    await page.getByTestId('effect-stat').selectOption('atk');
    await page.getByTestId('effect-stages').fill('-1');
    await page.getByTestId('effect-target').selectOption('opponent');
    await page.getByTestId('effect-description').fill('命中后降低攻击');
    fixture.state.failNext = 'insert::effect_data';
    await page.getByTestId('effect-save').click();
    await expect(page.getByTestId('effect-error')).toContainText('模拟保存失败');
    await expect(page.getByTestId('effect-code')).toHaveValue('browser_attack_drop');
    await page.getByTestId('effect-save').click();
    await expect(page.getByTestId('effect-row-1')).toContainText('browser_attack_drop');
    assert.deepEqual(fixture.state.effects[0].hooks, ['on_hit']);
    assert.deepEqual(fixture.state.effects[0].params,
      {code: 'stages_boost', stat: 'atk', stages: -1, target: 'opponent'});
    console.log('PASS: effect creation, typed parameters and failed-save retry');

    await page.getByTestId('effect-edit-1').click();
    await expect(page.getByTestId('effect-stages')).toHaveValue('-1');
    await expect(page.getByTestId('effect-target')).toHaveValue('opponent');
    await page.getByTestId('effect-type').selectOption('status_inflict');
    await page.getByTestId('effect-status').selectOption('burn');
    await page.getByTestId('effect-chance').fill('256');
    await expect(page.getByTestId('effect-save')).toBeDisabled();
    await page.getByTestId('effect-chance').fill('');
    await expect(page.getByTestId('effect-save')).toBeDisabled();
    await page.getByTestId('effect-chance').fill('30');
    await page.getByTestId('effect-save').click();
    await expect(page.getByTestId('effect-save')).toHaveCount(0);
    assert.deepEqual(fixture.state.effects[0].params,
      {code: 'status_inflict', status: 'burn', chance: 30});
    await page.getByTestId('effect-refresh').click();
    await expect(page.getByTestId('effect-row-1')).toContainText('browser_attack_drop');
    await page.getByTestId('effect-edit-1').click();
    await expect(page.getByTestId('effect-type')).toHaveValue('status_inflict');
    await expect(page.getByTestId('effect-status')).toHaveValue('burn');
    await expect(page.getByTestId('effect-chance')).toHaveValue('30');
    await page.getByTestId('effect-cancel').click();
    console.log('PASS: effect editing and list refresh');

    await page.getByRole('button', {name: '技能数据', exact: true}).click();
    await page.getByTestId('skill-edit-1').click();
    await page.getByTestId('skill-effect-id').selectOption('1');
    fixture.state.failNext = 'set::skill_type';
    await page.getByTestId('skill-save').click();
    await expect.poll(() => fixture.state.failNext).toBe(null);
    await expect(page.getByTestId('skill-save')).toBeEnabled();
    await expect(page.getByTestId('skill-effect-id')).toHaveValue('1');
    assert.equal(fixture.state.skills[0].effect_id, 0);
    await page.getByTestId('skill-save').click();
    await expect(page.getByTestId('skill-save')).toHaveCount(0);
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    assert.equal(fixture.state.skills[0].effect_id, 1);
    await page.getByTestId('skill-edit-1').click();
    await expect(page.getByTestId('skill-effect-id')).toHaveValue('1');
    await page.getByPlaceholder('技能名称', {exact: true}).fill('保留效果的技能');
    await page.getByTestId('skill-save').click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect.poll(() => fixture.state.skills[0].name).toBe('保留效果的技能');
    assert.equal(fixture.state.skills[0].effect_id, 1);
    // Reload the entire application: the binding must survive API deserialization.
    await page.reload();
    await page.getByRole('button', {name: '技能数据', exact: true}).click();
    await page.getByTestId('skill-edit-1').click();
    await expect(page.getByTestId('skill-effect-id')).toHaveValue('1');
    await page.getByTestId('skill-save').click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    console.log('PASS: skill binding survives save, rename and full-page reload');

    fixture.state.failNext = 'list::effect_data';
    await page.getByTestId('skill-edit-1').click();
    await expect(page.getByTestId('skill-effect-load-error')).toContainText('模拟保存失败');
    await expect(page.getByTestId('skill-effect-id')).toHaveValue('1');
    await page.getByPlaceholder('技能名称', {exact: true}).fill('加载失败仍保留绑定');
    await page.getByTestId('skill-save').click();
    await expect(page.getByTestId('skill-save')).toHaveCount(0);
    assert.equal(fixture.state.skills[0].effect_id, 1);
    console.log('PASS: failed effect-list loading preserves an existing binding');

    await page.getByTestId('nav-effects').click();
    await page.getByTestId('effect-delete-1').click();
    await page.getByTestId('effect-confirm-delete').click();
    await expect(page.getByTestId('effect-delete-error')).toContainText('该效果仍被技能引用，不能删除');
    assert.equal(fixture.state.effects.length, 1);
    // Close the failed delete dialog if it remains open, preserving recoverability.
    if (await page.getByTestId('effect-cancel').count()) await page.getByTestId('effect-cancel').click();
    await page.getByRole('button', {name: '技能数据', exact: true}).click();
    await page.getByTestId('skill-edit-1').click();
    await page.getByTestId('skill-effect-unbind').click();
    await page.getByTestId('skill-save').click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect.poll(() => fixture.state.skills[0].effect_id).toBe(0);
    await page.getByTestId('nav-effects').click();
    await page.getByTestId('effect-delete-1').click();
    await page.getByTestId('effect-confirm-delete').click();
    await expect(page.getByTestId('effect-row-1')).toHaveCount(0);
    assert.equal(fixture.state.effects.length, 0);
    console.log('PASS: referenced-delete error, explicit unbind and effect deletion');

    assert.deepEqual(fixture.state.unexpected, [], 'No unexpected fixture requests');
    assert.deepEqual(errors, [], 'No uncaught browser errors');
    await context.tracing.stop();
  } catch (error) {
    await fs.mkdir(artifacts, {recursive: true});
    if (page) await page.screenshot({path: path.join(artifacts, 'failure.png'), fullPage: true}).catch(() => {});
    if (context) await context.tracing.stop({path: path.join(artifacts, 'trace.zip')}).catch(() => {});
    await fs.writeFile(path.join(artifacts, 'failure.json'), JSON.stringify({
      error: String(error), errors, unexpected: fixture.state.unexpected,
      requests: fixture.state.requests,
    }, null, 2));
    throw error;
  } finally {
    if (browser) await browser.close();
    await fixture.close();
  }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
