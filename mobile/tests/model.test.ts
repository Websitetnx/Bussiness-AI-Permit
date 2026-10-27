import test from 'node:test';
import assert from 'node:assert/strict';
import { fields, formEntries, homeRoute, initialValues, internalRoute, isDownload, namedValues, validate, validateBaseUrl } from '../src/model.ts';
import type { NativeNode } from '../src/types.ts';

const field = (key: string, name: string, type: string, extra: Partial<NativeNode> = {}): NativeNode => ({ key, name, type, kind: 'field', ...extra });

test('PHP multipart names, unchecked controls, disabled fields, and clicked submit actions are preserved', () => {
  const nodes = [field('a', 'csrf_token', 'hidden', { value: 'server-csrf' }),
    field('b', 'new_lbt_rate_percent[Retail]', 'text', { value: '2.5' }),
    field('c', 'quarterly_fee_keys[]', 'checkbox', { value: 'lbt', checked: true }),
    field('d', 'quarterly_fee_keys[]', 'checkbox', { value: 'bfp' }),
    field('e', 'no', 'text', { disabled: true, value: 'omit' }), field('f', 'payment_proof', 'file')];
  const values = initialValues(nodes);
  values.f = { uri: 'file:///proof.pdf', name: 'proof.pdf', type: 'application/pdf' };
  const entries = formEntries(nodes, values, { key: 'submit', kind: 'button', name: 'intent', value: 'preview' });
  assert.deepEqual(entries.map(([key]) => key), ['csrf_token', 'new_lbt_rate_percent[Retail]', 'quarterly_fee_keys[]', 'payment_proof', 'intent']);
  assert.equal(entries.at(-1)?.[1], 'preview');
  assert.equal(typeof entries.at(-2)?.[1], 'object');
});

test('renewal preview and OTP resend bypass required fields but normal submission does not', () => {
  const nodes = [field('a', 'otp_code', 'text', { required: true, label: 'Code' })];
  assert.equal(validate(nodes, initialValues(nodes)), 'Code is required.');
  assert.equal(validate(nodes, initialValues(nodes), { key: 'b', kind: 'button', skipValidation: true }), null);
});

test('forms require the occupancy alternative and retain separately selected array checkboxes', () => {
  const nodes = [field('a', 'occupancy_doc', 'file'), field('b', 'occupancy_affidavit_doc', 'file'),
    field('c', 'fees[]', 'checkbox', { value: 'one', checked: true }), field('d', 'fees[]', 'checkbox', { value: 'two' })];
  const values = initialValues(nodes);
  assert.match(validate(nodes, values)!, /Occupancy Permit/);
  values.b = { uri: 'file:///proof.pdf', name: 'proof.pdf', type: 'application/pdf' };
  assert.equal(validate(nodes, values), null);
  const restored = initialValues(nodes, namedValues(nodes, values));
  assert.equal(restored.c, true);
  assert.equal(restored.d, false);
});

test('hidden server tokens refresh and passwords are never cached in drafts', () => {
  const nodes = [field('a', 'quote_token', 'hidden', { value: 'fresh' }), field('b', 'password', 'password'), field('c', 'payer_name', 'text')];
  assert.equal(initialValues(nodes, { quote_token: 'stale' }).a, 'fresh');
  assert.equal(namedValues(nodes, { a: 'fresh', b: 'secret', c: 'Owner' }).password, undefined);
});

test('inputs nested inside fee tables remain part of the parent form', () => {
  const nodes: NativeNode[] = [{ key: 'table', kind: 'table', rows: [[[field('fee', 'amount', 'text')]]] }];
  assert.equal(fields(nodes)[0].name, 'amount');
});

test('credentials stay inside the exact server origin and project path', () => {
  const base = 'https://example.test/permit%20office';
  assert.equal(internalRoute('/permit%20office/application.php?id=5', base), 'application.php?id=5');
  assert.equal(internalRoute('https://example.test/permit%20office/certificate.php?id=1', base), 'certificate.php?id=1');
  for (const url of ['https://evil.test/login.php', '//evil.test/login.php', 'https://example.test/permit%20office-evil/login.php', 'javascript:alert(1)', '../config.php']) assert.equal(internalRoute(url, base), null);
  assert.throws(() => validateBaseUrl('http://example.test', false));
  assert.throws(() => validateBaseUrl('https://user:password@example.test', true));
});

test('role homes and protected downloads include Treasurer reports', () => {
  assert.equal(homeRoute('treasurer'), 'admin/payments.php');
  assert.equal(homeRoute('staff'), 'admin/index.php');
  assert.equal(homeRoute('applicant'), 'dashboard.php');
  assert.equal(isDownload('document.php?id=3'), true);
  assert.equal(isDownload('admin/reports.php?type=collections&format=csv'), true);
  assert.equal(isDownload('certificate.php?id=3'), false);
});
