const { getDefaultConfig } = require('expo/metro-config');
const http = require('node:http');
const config = getDefaultConfig(__dirname);
const previous = config.server.enhanceMiddleware;
config.server.enhanceMiddleware = (middleware, server) => {
  const next = previous ? previous(middleware, server) : middleware;
  return (req, res, connectNext) => {
    if (!req.url?.startsWith('/api/v1/')) return next(req, res, connectNext);
    const upstream = http.request({ hostname: '127.0.0.1', port: 8080, path: req.url,
      method: req.method, headers: { ...req.headers, host: '127.0.0.1:8080' } }, response => {
      res.writeHead(response.statusCode || 502, response.headers);
      response.pipe(res);
    });
    const unavailable = () => {
      if (!res.headersSent) {
        res.writeHead(503, { 'Content-Type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify({ error: 'Não foi possível conectar à API. Tente novamente.' }));
      } else res.destroy();
    };
    upstream.setTimeout(15000, () => upstream.destroy());
    upstream.on('error', unavailable);
    req.on('aborted', () => upstream.destroy());
    req.pipe(upstream);
  };
};
module.exports = config;
