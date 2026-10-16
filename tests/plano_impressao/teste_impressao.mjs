// Impressão do prontuário pelo public/index.php real (PHP em WebAssembly).
import { PHP, PHPRequestHandler, setPhpIniEntries } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const WORK = process.env.WORK || new URL('../..', import.meta.url).pathname.replace(/\/$/, '');
const ids = JSON.parse(process.env.IDS);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/work');
await php.mount('/work', createNodeFsMountHandler(WORK));
await setPhpIniEntries(php, { auto_prepend_file: '/work/tests/plano_impressao/env_teste.php' });
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
const base = `/planos/${ids.plan}/imprimir`;

const editor = await pro.req('GET', `/planos/${ids.plan}`);
ok(editor.status === 200 && editor.text.includes(base), 'editor do plano tem o botão "Imprimir plano"');

const page = await pro.req('GET', base);
const t = page.text;
ok(page.status === 200, `nutricionista abre a impressão (HTTP ${page.status})`);
ok(t.includes('Plano de reeducação') && t.includes('Paciente Plano'), 'título e paciente');
ok(t.includes('<strong>Versão:</strong> 1') && t.includes('Publicado em:'), 'versão e data de publicação');
ok(t.includes('12:30') && t.includes('Almoço') && t.indexOf('Almoço') < t.indexOf('Lanche da tarde'), 'refeições em ordem, com horário');
ok(t.includes('Arroz integral cozido') && t.includes('120 g') && !t.includes('999'), 'quantidade da versão publicada (alteração posterior não aparece)');
ok(t.includes('1,5 concha'), 'quantidade fracionada em medida caseira');
ok(t.includes('Pode substituir por:') && t.includes('Batata-doce cozida — 150 g (assada)'), 'substituições listadas');
ok(t.includes('Comece pela salada.') && t.includes('Pode ser arroz branco se faltar'), 'orientações da refeição e do item');
ok(t.includes('Evite frituras &lt;b&gt;nunca&lt;/b&gt;.') && t.includes('Beba água ao longo do dia.<br />'), 'orientações gerais escapadas, com quebra de linha');
ok(!/kcal/.test(t.replace(/Mostrar calorias e macronutrientes/, '')), 'sem calorias por padrão');
ok(t.includes('Dra. Bia Plano — CRN-3 55555'), 'assinatura com CRN do autor do plano');
ok(!/<script>(?!<\/script>)/.test(t.replace(/<script src="[^"]+"><\/script>/g, '')) && !/on(click|submit)=/.test(t), 'sem JavaScript inline (CSP)');
ok(!t.includes('pi-alert'), 'plano publicado vigente sem aviso');

const withNut = await pro.req('GET', `${base}?nutricional=1`);
ok(withNut.text.includes('Valores diários estimados') && /\d kcal/.test(withNut.text), 'opção mostra calorias e macronutrientes');

const draft = await pro.req('GET', `/planos/${ids.draft}/imprimir`);
ok(draft.status === 200 && draft.text.includes('Rascunho — plano não publicado') && draft.text.includes('Café da manhã'), 'rascunho sai com aviso de não publicado');

const secPage = await sec.req('GET', base);
ok(secPage.status === 403 && !secPage.text.includes('Paciente Plano'), `secretaria não imprime plano (HTTP ${secPage.status})`);
const foreign = await pro.req('GET', `/planos/${ids.foreign}/imprimir`);
ok(foreign.status === 404 && !foreign.text.includes('Paciente Outra'), `plano de outra clínica bloqueado (HTTP ${foreign.status})`);
const anon = await new Browser().req('GET', base);
ok(anon.status === 302, `sem login redireciona (HTTP ${anon.status})`);

const audit = JSON.parse((await php.run({ code: `<?php $d=new PDO('sqlite:/work/storage/plano_impressao.sqlite'); echo json_encode($d->query("SELECT user_id, resource_id, details FROM audit_logs WHERE action='MEAL_PLAN_PRINTED'")->fetchAll(PDO::FETCH_ASSOC));` })).text);
ok(audit.length === 3 && audit.every(a => Number(a.user_id) === ids.user), `cada impressão auditada com o usuário (${audit.length} eventos)`);
ok(audit.some(a => JSON.parse(a.details).from_snapshot === true) && audit.some(a => JSON.parse(a.details).status === 'draft'), 'auditoria indica versão publicada ou rascunho');

console.log(failures ? `\n${failures} FALHA(S)` : '\nIMPRESSÃO DO PLANO ALIMENTAR APROVADA');
process.exit(failures ? 1 : 0);
