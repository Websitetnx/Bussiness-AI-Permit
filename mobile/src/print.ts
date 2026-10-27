import qrcode from 'qrcode-generator';
import * as Print from 'expo-print';
import * as Sharing from 'expo-sharing';
import * as FileSystem from 'expo-file-system/legacy';
import type { NativeNode, Screen } from './types';

const escape = (text = '') => text.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]!));

export function qrSvg(value: string): string {
  const qr = qrcode(0, 'M');
  qr.addData(value);
  qr.make();
  return qr.createSvgTag({ cellSize: 3, margin: 12, scalable: true });
}

function markup(nodes: NativeNode[]): string {
  return nodes.map(node => {
    if (node.kind === 'form' || node.kind === 'field' || node.kind === 'button' || node.kind === 'link') return '';
    if (node.kind === 'qr') return `<div class="qr">${qrSvg(node.text ?? '')}</div>`;
    if (node.kind === 'text') {
      const tag = node.tone === 'heading' ? 'h2' : 'p';
      return `<${tag}>${escape(node.text)}</${tag}>`;
    }
    if (node.kind === 'table') return `<table><thead><tr>${(node.headers ?? []).map(h => `<th>${escape(h)}</th>`).join('')}</tr></thead><tbody>${(node.rows ?? []).map(row => `<tr>${row.map(cell => `<td>${markup(cell)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
    return `<section>${markup(node.children ?? [])}</section>`;
  }).join('');
}

export async function sharePdf(screen: Screen): Promise<void> {
  const html = `<!doctype html><html><head><meta charset="utf-8"><style>
    body{font-family:Arial,sans-serif;color:#101b2a;padding:24px;font-size:11pt}
    h1{color:#075f80;font-size:23pt}h2{font-size:15pt}p{margin:7px 0;line-height:1.4}
    section{margin:10px 0}table{border-collapse:collapse;width:100%;font-size:9pt}
    td,th{padding:7px;border:1px solid #dce6eb;text-align:left}.qr{width:150px;height:150px}
    .qr svg{width:150px;height:150px}
  </style></head><body><h1>${escape(screen.title)}</h1>${markup(screen.nodes)}</body></html>`;
  const result = await Print.printToFileAsync({ html });
  try {
    if (!(await Sharing.isAvailableAsync())) throw new Error('PDF sharing is unavailable on this device.');
    await Sharing.shareAsync(result.uri, { mimeType: 'application/pdf', UTI: '.pdf', dialogTitle: screen.title });
  } finally { await FileSystem.deleteAsync(result.uri, { idempotent: true }); }
}
