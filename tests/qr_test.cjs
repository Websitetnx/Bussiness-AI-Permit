const assert = require('node:assert/strict');
const qrcode = require('../frontend/assets/vendor/qrcode.js');
const jsQR = require(process.env.QR_DECODER_PATH);
const value = 'https://permits.example.test/verify-permit.php?token=' + 'a'.repeat(64);
const qr = qrcode(0, 'M'); qr.addData(value, 'Byte'); qr.make();
const n = qr.getModuleCount(), scale = 5, margin = 4, width = (n + margin * 2) * scale;
const pixels = new Uint8ClampedArray(width * width * 4).fill(255);
for (let y = 0; y < width; y++) for (let x = 0; x < width; x++) {
  const row = Math.floor(y / scale) - margin, col = Math.floor(x / scale) - margin;
  if (row >= 0 && col >= 0 && row < n && col < n && qr.isDark(row, col)) {
    const i = (y * width + x) * 4; pixels[i] = pixels[i + 1] = pixels[i + 2] = 0;
  }
}
assert.equal(jsQR(pixels, width, width).data, value);
assert.match(qr.createSvgTag({cellSize:4,margin:16,scalable:true}), /<svg/);
console.log('QR image independently decoded to the exact verification URL.');
