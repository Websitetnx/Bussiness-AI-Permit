import * as SecureStore from 'expo-secure-store';
import * as FileSystem from 'expo-file-system/legacy';
import * as Sharing from 'expo-sharing';
import type { Entry, Envelope } from './types';
import { internalRoute, validateBaseUrl } from './model';

const KEY = 'permit.connection.v1';
export const allowHttp = __DEV__ && process.env.EXPO_PUBLIC_ALLOW_HTTP === 'true';

export class PermitApi {
  baseUrl = '';
  private session = '';
  csrf = '';

  async restore(): Promise<boolean> {
    const stored = await SecureStore.getItemAsync(KEY);
    if (stored) {
      const data = JSON.parse(stored) as { baseUrl: string; session: string; csrf: string };
      this.baseUrl = validateBaseUrl(data.baseUrl, allowHttp);
      this.session = data.session;
      this.csrf = data.csrf;
    } else if (process.env.EXPO_PUBLIC_API_URL) {
      this.baseUrl = validateBaseUrl(process.env.EXPO_PUBLIC_API_URL, allowHttp);
    }
    return this.baseUrl !== '';
  }

  async configure(value: string): Promise<void> {
    this.baseUrl = validateBaseUrl(value, allowHttp);
    this.session = '';
    this.csrf = '';
    await this.persist();
  }

  private async persist(): Promise<void> {
    await SecureStore.setItemAsync(KEY, JSON.stringify({ baseUrl: this.baseUrl, session: this.session, csrf: this.csrf }),
      { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY });
  }

  async forget(): Promise<void> {
    this.session = '';
    this.csrf = '';
    await this.persist();
  }

  url(route: string): string {
    const safe = internalRoute(route, this.baseUrl);
    if (!safe) throw new Error('This link is outside the PERMIT server.');
    const [path, query = ''] = safe.split('?');
    const params = new URLSearchParams(query);
    params.set('route', path);
    return `${this.baseUrl}/api/mobile.php?${params.toString()}`;
  }

  async request(route: string, method = 'GET', entries: Entry[] = []): Promise<Envelope> {
    let target = route;
    if (method === 'GET' && entries.length) {
      const query = new URLSearchParams(target.split('?')[1] ?? '');
      entries.forEach(([name, value]) => { if (typeof value === 'string') query.set(name, value); });
      target = target.split('?')[0] + '?' + query.toString();
    }
    let body: FormData | undefined;
    if (method === 'POST') {
      body = new FormData();
      for (const [name, value] of entries) {
        if (name === 'csrf_token') continue;
        // React Native's multipart implementation accepts { uri, name, type }.
        body.append(name, value as unknown as Blob);
      }
      body.append('csrf_token', this.csrf);
    }
    const controller = new AbortController();
    // Uploads may trigger document scanning; never retry a POST automatically.
    const timeout = setTimeout(() => controller.abort(), method === 'POST' ? 180_000 : 30_000);
    try {
      const response = await fetch(this.url(target), {
        method, body, headers: { Accept: 'application/json', 'X-Permit-Session': this.session },
        credentials: 'omit', signal: controller.signal, redirect: 'error',
      });
      const raw = await response.text();
      let result: Envelope;
      try { result = JSON.parse(raw) as Envelope; }
      catch { throw new Error('The server did not return mobile data. Check the project URL and deploy api/mobile.php.'); }
      if (result.session) this.session = result.session;
      if (result.csrf) this.csrf = result.csrf;
      await this.persist();
      if (!response.ok || !result.ok) throw new Error(result.error || `Request failed (${response.status}).`);
      if (result.version !== 1) throw new Error('This server uses an unsupported mobile API version.');
      return result;
    } catch (error) {
      if (error instanceof Error && error.name === 'AbortError') throw new Error(method === 'POST'
        ? 'The request timed out. Check your application or payment status before submitting again; the server may have saved it.'
        : 'The server took too long to respond. Check your connection and try again.');
      throw error;
    } finally { clearTimeout(timeout); }
  }

  async download(route: string): Promise<void> {
    const file = `${FileSystem.cacheDirectory}permit-${Date.now()}.download`;
    let shareFile = file;
    try {
      const result = await FileSystem.downloadAsync(this.url(route), file,
        { headers: { 'X-Permit-Session': this.session, Accept: '*/*' } });
      const headers = Object.fromEntries(Object.entries(result.headers).map(([key, value]) => [key.toLowerCase(), value]));
      const mime = headers['content-type']?.split(';')[0] ?? '';
      if (result.status !== 200 || mime === 'application/json') throw new Error('This document is unavailable or your session expired. Refresh and sign in again.');
      const extension = ({ 'application/pdf': 'pdf', 'image/png': 'png', 'image/jpeg': 'jpg', 'text/csv': 'csv' } as Record<string, string>)[mime];
      if (!extension) throw new Error('The server returned an unsupported document type.');
      shareFile = file.replace('.download', '.' + extension);
      await FileSystem.moveAsync({ from: file, to: shareFile });
      if (!(await Sharing.isAvailableAsync())) throw new Error('Document sharing is unavailable on this device.');
      await Sharing.shareAsync(shareFile, { mimeType: mime, dialogTitle: 'Open or save PERMIT document' });
    } finally {
      await Promise.all([file, shareFile].map(uri => FileSystem.deleteAsync(uri, { idempotent: true }).catch(() => undefined)));
    }
  }
}
