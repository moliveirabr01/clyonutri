#!/usr/bin/env python3
"""Servidor SMTP de teste (NI2-409 / recuperação de senha).

Fala SMTP real: TLS implícito (como a porta 465 da HostGator) ou STARTTLS, AUTH LOGIN/PLAIN com
usuário e senha conferidos, e grava cada mensagem recebida como .eml para os testes inspecionarem.

Uso: fake_smtp.py --port 2465 --dir /tmp/mails --cert c.pem --key k.pem --user u@x --password p [--starttls]
"""
import argparse
import base64
import os
import socketserver
import ssl
import time

p = argparse.ArgumentParser()
p.add_argument('--port', type=int, required=True)
p.add_argument('--dir', required=True)
p.add_argument('--cert', required=True)
p.add_argument('--key', required=True)
p.add_argument('--user', required=True)
p.add_argument('--password', required=True)
p.add_argument('--starttls', action='store_true', help='conexão em texto com STARTTLS (porta 587)')
args = p.parse_args()
os.makedirs(args.dir, exist_ok=True)

ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(args.cert, args.key)


class Handler(socketserver.StreamRequestHandler):
    def setup(self):
        if not args.starttls:
            self.request = ctx.wrap_socket(self.request, server_side=True)
        super().setup()

    def send(self, line):
        self.wfile.write((line + '\r\n').encode())
        self.wfile.flush()

    def readline(self):
        line = self.rfile.readline()
        if not line:
            raise ConnectionError
        return line.decode('utf-8', 'replace').rstrip('\r\n')

    def handle(self):
        tls = not args.starttls
        authed = False
        mail_from, rcpts = None, []
        self.send('220 fake-smtp.gate ESMTP pronto')
        try:
            while True:
                line = self.readline()
                cmd = line.split(' ')[0].upper()
                if cmd in ('EHLO', 'HELO'):
                    exts = ['250-fake-smtp.gate', '250-8BITMIME']
                    if args.starttls and not tls:
                        exts.append('250-STARTTLS')
                    if tls:
                        exts.append('250-AUTH LOGIN PLAIN')
                    exts.append('250 SIZE 10485760')
                    for e in exts:
                        self.send(e)
                elif cmd == 'STARTTLS' and args.starttls and not tls:
                    self.send('220 vamos de TLS')
                    self.request = ctx.wrap_socket(self.request, server_side=True)
                    self.rfile = self.request.makefile('rb')
                    self.wfile = self.request.makefile('wb')
                    tls = True
                elif cmd == 'AUTH':
                    if not tls:
                        self.send('538 encryption required')
                        continue
                    parts = line.split(' ')
                    if parts[1].upper() == 'PLAIN':
                        raw = base64.b64decode(parts[2]).split(b'\0')
                        user, pwd = raw[1].decode(), raw[2].decode()
                    else:
                        self.send('334 VXNlcm5hbWU6')
                        user = base64.b64decode(self.readline()).decode()
                        self.send('334 UGFzc3dvcmQ6')
                        pwd = base64.b64decode(self.readline()).decode()
                    if user == args.user and pwd == args.password:
                        authed = True
                        self.send('235 autenticado')
                    else:
                        self.send('535 5.7.8 credenciais invalidas')
                elif cmd == 'MAIL':
                    if not authed:
                        self.send('530 5.7.0 autenticacao exigida')
                        continue
                    mail_from, rcpts = line[10:].strip('<>'), []
                    self.send('250 ok')
                elif cmd == 'RCPT':
                    rcpts.append(line[8:].strip('<>'))
                    self.send('250 ok')
                elif cmd == 'DATA':
                    self.send('354 envie a mensagem; termine com .')
                    lines = []
                    while True:
                        l = self.readline()
                        if l == '.':
                            break
                        lines.append(l[1:] if l.startswith('..') else l)
                    name = f"{time.time():.6f}_{rcpts[0] if rcpts else 'x'}.eml"
                    with open(os.path.join(args.dir, name), 'w', encoding='utf-8') as f:
                        f.write(f"X-Envelope-From: {mail_from}\r\nX-Envelope-To: {','.join(rcpts)}\r\n")
                        f.write('\r\n'.join(lines) + '\r\n')
                    self.send('250 mensagem aceita')
                elif cmd == 'QUIT':
                    self.send('221 tchau')
                    return
                elif cmd == 'NOOP':
                    self.send('250 ok')
                else:
                    self.send('502 comando nao implementado')
        except (ConnectionError, ssl.SSLError, OSError):
            return


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


with Server(('127.0.0.1', args.port), Handler) as srv:
    print(f"fake SMTP em 127.0.0.1:{args.port} ({'STARTTLS' if args.starttls else 'TLS implícito'}) → {args.dir}", flush=True)
    srv.serve_forever()
