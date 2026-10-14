// Servidor do build web do app (Expo) para o gate (NI2-409).
// Serve os arquivos do build e repassa /api/* para o servidor PHP real, na mesma origem —
// exatamente como o app web roda em produção (window.location.origin). Sem mocks.
// Uso: node mobile-proxy.mjs <diretorio_do_build> <porta> <url_da_api>
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const [dir, port, api] = process.argv.slice(2);
if (!dir || !port || !api) { console.error('Uso: node mobile-proxy.mjs <build> <porta> <api>'); process.exit(2); }
const root = path.resolve(dir);
const target = new URL(api);
const types = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.ttf': 'font/ttf', '.woff2': 'font/woff2' };

http.createServer((req, res) => {
  const url = new URL(req.url, 'http://local');
  if (url.pathname.startsWith('/api/') || url.pathname === '/up') {
    const headers = { ...req.headers, host: target.host };
    const upstream = http.request({ hostname: target.hostname, port: target.port, path: req.url, method: req.method, headers }, (up) => {
      res.writeHead(up.statusCode, up.headers);
      up.pipe(res);
    });
    upstream.on('error', (e) => { res.writeHead(502); res.end(`proxy: ${e.message}`); });
    req.pipe(upstream);
    return;
  }
  let file = path.join(root, decodeURIComponent(url.pathname));
  if (!file.startsWith(root) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) file = path.join(root, 'index.html');
  res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
}).listen(Number(port), '127.0.0.1', () => console.log(`app web em http://127.0.0.1:${port} → API ${api}`));
