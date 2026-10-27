'use strict';
// Render permit-verification QR codes in the browser.
document.querySelectorAll('[data-permit-verification]').forEach(function (element) {
  const value = element.dataset.permitVerification;
  if (!value || typeof qrcode !== 'function') return;
  const qr = qrcode(0, 'M');
  qr.addData(value, 'Byte');
  qr.make();
  element.innerHTML = qr.createSvgTag({cellSize: 4, margin: 16, scalable: true});
  element.setAttribute('role', 'img');
  element.setAttribute('aria-label', 'Scan to verify the permit status');
});
