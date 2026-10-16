// Impressão do prontuário pelo public/index.php real (PHP em WebAssembly).
import { PHP, PHPRequestHandler, setPhpIniEntries } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const WORK = process.env.WORK || new URL('../..', import.meta.url).pathname.replace(/\/$/, '');
const ids = JSON.parse(process.env.IDS);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/work');
await php.mount('/work', createNodeFsMountHandler(WORK));
await setPhpIniEntries(php, { auto_prepend_file: '/work/tests/prontuario_impressao/env_teste.php' });
const handler = new PHPRequestHandler({ php, documentRoot: '/work/public', absoluteUrl: 'http://localhost', cookieStore: false,
  getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }) });
const enc = new TextEncoder();
let failures = 0;
const ok = (c, m) => { console.log((c ? '[PASS] ' : '[FAIL] ') + m); if (!c) failures++; };

class Browser {
  constructor() { this.c = {}; }
  async req(method, url, form) {
    const h = {};
    const ck = Object.entries(this.c).map(([k, v]) => `${k}=${v}`).join('; ');
    if (ck) h.cookie = ck;
    let body;
    if (form) { h['content-type'] = 'application/x-www-form-urlencoded'; body = enc.encode(new URLSearchParams(form).toString()); }
    const res = await handler.request({ method, url, headers: h, body });
    for (const sc of res.headers['set-cookie'] || []) { const [kv] = sc.split(';'); const i = kv.indexOf('='); this.c[kv.slice(0, i)] = kv.slice(i + 1); }
    return { status: res.httpStatusCode, text: res.text };
  }
  async login(email) {
    const page = await this.req('GET', '/login');
    const csrf = page.text.match(/name="_csrf_token" value="([^"]+)"/)[1];
    return this.req('POST', '/login', { _csrf_token: csrf, email, password: 'Senha-Forte-2026!' });
  }
}

const pro = new Browser(), sec = new Browser();
await pro.login('pro@ex.test');
await sec.login('sec@ex.test');
const base = `/pacientes/${ids.patient}/prontuario/imprimir`;

const record = await pro.req('GET', `/pacientes/${ids.patient}/prontuario`);
ok(record.status === 200 && record.text.includes(base), 'tela do prontuário tem o botão "Imprimir prontuário"');

const page = await pro.req('GET', base);
const t = page.text;
ok(page.status === 200, `nutricionista abre a impressão (HTTP ${page.status})`);
ok(t.includes('Paciente Impressão') && t.includes('20/05/1990') && t.includes('Feminino'), 'identificação do paciente');
ok(t.includes('Dra. Ana Teste — CRN-3 12345'), 'linha de assinatura com nome e CRN');
ok(t.includes('(corrigido)') && t.includes('Retificação — motivo: Correção de digitação'), 'versão vigente e motivo da retificação');
ok(!t.includes('fibras.</dd>') && !t.includes('Substituído pela versão'), 'versão substituída oculta por padrão');
ok(t.includes('Retorno &lt;script&gt;alert(1)&lt;/script&gt;') && !t.includes('<script>alert(1)'), 'conteúdo do registro escapado (sem HTML injetado)');
ok(t.includes('Linha 1<br />') && t.includes('registrado em teleconsulta') && !t.includes('teleconsultation_session_id') && !/Teleconsultation session id/.test(t), 'quebras de linha mantidas; campos técnicos ocultos');
ok(t.includes('72,4') && t.includes('26,6'), 'tabela de antropometria');
ok(!/<script>(?!<\/script>)/.test(t.replace(/<script src="[^"]+"><\/script>/g, '')) && !/on(click|submit)=/.test(t), 'sem JavaScript inline (CSP)');
ok(t.includes('Documento confidencial'), 'rodapé de confidencialidade');

const hist = await pro.req('GET', `${base}?historico=1`);
ok(hist.text.includes('Substituído pela versão') && !hist.text.includes('Registro anulado'), 'histórico mostra a versão substituída sem rotulá-la como anulada');

const filtered = await pro.req('GET', `${base}?ate=2025-12-31&historico=1`);
ok(filtered.text.includes('Nenhum registro no período') && !filtered.text.includes('Primeira consulta</h3>'), 'filtro por período remove registros fora dele');

const stored = JSON.parse((await php.run({ code: `<?php $d=new PDO('sqlite:/work/storage/prontuario_impressao.sqlite'); echo json_encode($d->query("SELECT created_at FROM clinical_records ORDER BY id LIMIT 1")->fetchColumn());` })).text);
const [dt, tm] = stored.split(' ');
const expected = `${dt.split('-').reverse().join('/')} ${tm.slice(0, 5)}`;
ok(page.text.includes(expected) || hist.text.includes(expected), `horário impresso igual ao gravado, sem deslocamento de fuso (${expected})`);
ok(!page.text.includes('pi-alert'), 'sem aviso de integridade com o histórico íntegro');

const bad = await pro.req('GET', `${base}?de=2026-13-40`);
ok(bad.status === 422, `data inválida recusada (HTTP ${bad.status})`);
const inverted = await pro.req('GET', `${base}?de=2026-10-10&ate=2026-01-01`);
ok(inverted.status === 422, `período invertido recusado (HTTP ${inverted.status})`);

const secPage = await sec.req('GET', base);
ok(secPage.status === 403 && !secPage.text.includes('Paciente Impressão'), `secretaria não imprime prontuário (HTTP ${secPage.status})`);
const foreign = await pro.req('GET', `/pacientes/${ids.foreign}/prontuario/imprimir`);
ok(foreign.status === 404 && !foreign.text.includes('Outra Clínica'), `paciente de outra clínica bloqueado (HTTP ${foreign.status})`);
const anon = await new Browser().req('GET', base);
ok(anon.status === 302, `sem login redireciona (HTTP ${anon.status})`);

// Adulteração direta no banco: o documento sai com aviso destacado.
await php.run({ code: `<?php $d=new PDO('sqlite:/work/storage/prontuario_impressao.sqlite'); $d->exec("UPDATE clinical_records SET content_json = REPLACE(content_json, 'fibras', 'FIBRAS') WHERE id = (SELECT MIN(id) FROM clinical_records)");` });
const tampered = await pro.req('GET', `${base}?historico=1`);
ok(tampered.status === 200 && tampered.text.includes('verificação de integridade do prontuário não foi confirmada'), 'prontuário adulterado sai com aviso de integridade');

const audit = JSON.parse((await php.run({ code: `<?php $d=new PDO('sqlite:/work/storage/prontuario_impressao.sqlite'); echo json_encode($d->query("SELECT user_id, details FROM audit_logs WHERE action='CLINICAL_RECORD_PRINTED'")->fetchAll(PDO::FETCH_ASSOC));` })).text);
ok(audit.length === 4 && audit.every(a => Number(a.user_id) === ids.user), `cada impressão válida auditada com o usuário (${audit.length} eventos; as recusadas não contam)`);
ok(audit.some(a => JSON.parse(a.details).include_history === true && JSON.parse(a.details).to === '2025-12-31'), 'auditoria guarda período e opções');
ok(audit.some(a => JSON.parse(a.details).integrity_valid === false), 'auditoria registra a impressão com integridade não confirmada');

console.log(failures ? `\n${failures} FALHA(S)` : '\nIMPRESSÃO DO PRONTUÁRIO APROVADA');
process.exit(failures ? 1 : 0);
