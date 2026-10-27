import type { Entry, FieldValue, NativeNode, Values } from './types.ts';

export function fields(nodes: NativeNode[]): NativeNode[] {
  return nodes.flatMap(node => node.kind === 'field' ? [node] : [
    ...fields(node.children ?? []),
    ...(node.rows ?? []).flatMap(row => row.flatMap(cell => fields(cell))),
  ]);
}

export function initialValues(nodes: NativeNode[], saved: Values = {}): Values {
  return Object.fromEntries(fields(nodes).map(field => [field.key,
    field.type !== 'hidden' && !field.readonly && saved[draftKey(field)] !== undefined
      ? saved[draftKey(field)]
      : (field.type === 'checkbox' || field.type === 'radio' ? Boolean(field.checked) : field.value ?? ''),
  ]));
}

export function namedValues(nodes: NativeNode[], values: Values): Values {
  return Object.fromEntries(fields(nodes).filter(f => f.type !== 'hidden' && f.type !== 'password')
    .map(f => [draftKey(f), values[f.key] ?? '']));
}

function draftKey(field: NativeNode): string {
  return field.name! + (field.type === 'checkbox' || field.type === 'radio' ? ':' + field.value : '');
}

export function formEntries(nodes: NativeNode[], values: Values, button?: NativeNode): Entry[] {
  const entries: Entry[] = [];
  for (const field of fields(nodes)) {
    if (!field.name || field.disabled) continue;
    const value = values[field.key];
    if (field.type === 'checkbox' || field.type === 'radio') {
      if (value) entries.push([field.name, field.value ?? 'on']);
    } else if (field.type === 'file') {
      if (value && typeof value === 'object') entries.push([field.name, value]);
    } else entries.push([field.name, typeof value === 'string' ? value : '']);
  }
  if (button?.name) {
    // HTML submits only the clicked button, including preview/resend/verify actions.
    entries.push([button.name, button.value ?? '']);
  }
  return entries;
}

export function validate(nodes: NativeNode[], values: Values, button?: NativeNode): string | null {
  if (button?.skipValidation) return null;
  for (const field of fields(nodes)) {
    if (field.disabled || field.readonly || field.type === 'hidden') continue;
    const value: FieldValue = values[field.key] ?? '';
    const label = field.label ?? field.name ?? 'This field';
    if (field.required && (value === '' || value === false || (typeof value === 'string' && !value.trim()))) return `${label} is required.`;
    if (typeof value !== 'string' || value === '') continue;
    if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return `Enter a valid ${label.toLowerCase()}.`;
    if (field.minLength && value.length < field.minLength) return `${label} must contain at least ${field.minLength} characters.`;
    if (field.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) return `${label} must use YYYY-MM-DD.`;
    if (field.type === 'datetime-local' && !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) return `${label} must use YYYY-MM-DDTHH:mm.`;
    if (field.type === 'select' && field.options?.some(o => o.value === value && o.disabled)) return `Choose an available ${label.toLowerCase()}.`;
  }
  const list = fields(nodes);
  const occupancy = list.find(f => f.name === 'occupancy_doc');
  const affidavit = list.find(f => f.name === 'occupancy_affidavit_doc');
  if (occupancy && affidavit && !values[occupancy.key] && !values[affidavit.key]) return 'Attach an Occupancy Permit or an Affidavit of Undertaking.';
  return null;
}

export function homeRoute(role?: string): string {
  if (role === 'treasurer') return 'admin/payments.php';
  return ['admin', 'staff', 'superadmin'].includes(role ?? '') ? 'admin/index.php' : 'dashboard.php';
}

export function isDownload(route: string): boolean {
  return /^(document|document-version|payment-proof)\.php(?:\?|$)/.test(route)
    || (route.startsWith('admin/reports.php?') && new URLSearchParams(route.split('?')[1]).get('format') === 'csv');
}

/** Resolve only project routes; credentials never follow an external URL. */
export function internalRoute(href: string, baseUrl: string): string | null {
  if (/^(?:[a-z][a-z0-9+.-]*:|\/\/|\/)/i.test(href)) {
    const base = new URL(baseUrl + '/');
    const target = new URL(href, base);
    if (target.origin !== base.origin || !decodeURIComponent(target.pathname).startsWith(decodeURIComponent(base.pathname))) return null;
    const path = decodeURIComponent(target.pathname).slice(decodeURIComponent(base.pathname).length);
    return path + target.search;
  }
  const clean = href.split('#')[0];
  if (!/^(?:admin\/|api\/)?[a-z-]+\.php(?:\?|$)/.test(clean) || clean.includes('..')) return null;
  return clean;
}

export function validateBaseUrl(value: string, allowHttp: boolean): string {
  const url = new URL(value.trim());
  if (url.protocol !== 'https:' && !(allowHttp && url.protocol === 'http:')) throw new Error('Use an HTTPS server URL. HTTP is available only in a development build.');
  if (url.username || url.password || url.search || url.hash) throw new Error('Enter the project base URL without credentials, a query, or a fragment.');
  return url.toString().replace(/\/$/, '');
}
