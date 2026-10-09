// Runs the compiled production TypeScript client against public/index.php over TCP.
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, writeFileSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const net = require('node:net');
const { ApiService, setApiBaseUrl, getAuthToken, setAuthToken, setSessionExpiredHandler } = require('../mobile/.test-build/api.js');
const root = path.resolve(__dirname, '..');
const dir = mkdtempSync(path.join(tmpdir(), 'ni2-294-'));
const db = path.join(dir, 'ni2-294-test.sqlite');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: db };
const fixture = (action) => execFileSync('php', ['tests/support/ni2_294_fixture.php', action], { cwd: root, env, encoding: 'utf8' });
const inspect = () => JSON.parse(fixture('inspect'));
const rows = [];
let server, serverLog = '';
function check(name, actual) { assert.ok(actual, name); rows.push({ name, status: 'PASS' }); console.log('PASS ' + name); }
async function rejection(name, task, status) {
  try { await task(); assert.fail(name + ' unexpectedly succeeded'); }
  catch (e) { if (e.code === 'ERR_ASSERTION') throw e; check(name, status !== undefined ? e.status === status : e instanceof TypeError); }
}
const login = (email='patient-a@example.test') => ApiService.login({ email, password: 'PacienteTeste@2026' });
(async () => {
  fixture('prepare');
  const listener = net.createServer();
  await new Promise(r => listener.listen(0, '127.0.0.1', r));
  const port = listener.address().port;
  await new Promise(r => listener.close(r));
  const base = `http://127.0.0.1:${port}`;
  setApiBaseUrl(base);
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public'], { cwd: root, env, stdio: ['ignore','pipe','pipe'] });
  server.stdout.on('data', d => serverLog += d);
  server.stderr.on('data', d => serverLog += d);
  let ready=false;
  for (let i=0;i<60;i++) { try { ready=(await fetch(base+'/up')).ok; if(ready)break; } catch{} await new Promise(r=>setTimeout(r,50)); }
  check('Production front controller reachable over real HTTP', ready);
  const rawLogin = async body => {
    const res = await fetch(base+'/api/v1/auth/patient-login', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body) });
    return {status:res.status, body:await res.json()};
  };
  for (const [name, body, code] of [
    ['Identifier alone', {email:'patient-a@example.test'},401],
    ['Wrong password',{email:'patient-a@example.test',password:'incorrect'},401],
    ['Unknown identifier cannot match empty phone',{email:'missing@example.test',password:'PacienteTeste@2026'},401],
    ['Inactive patient',{email:'inactive@example.test',password:'PacienteTeste@2026'},401],
    ['Professional account cannot enter patient API',{email:'professional@example.test',password:'PacienteTeste@2026'},401],
    ['Malformed credential type',{email:[],password:'x'},400],
  ]) { const res=await rawLogin(body); check(name+' rejected without token',res.status===code && !res.body.token); }
  check('Rejected logins persisted no tokens',inspect().tokens.length===0);
  const before=inspect();
  let auth=await login();
  check('Compiled client sends password and receives patient session',auth.patient.id===1 && !!auth.expires_at);
  const persisted=inspect().tokens[0];
  check('Server persists SHA256 token, never plaintext',persisted.token===require('node:crypto').createHash('sha256').update(auth.token).digest('hex') && persisted.token!==auth.token);
  check('Token expires in 30 days UTC',Math.abs(Date.parse(persisted.expires_at.replace(' ','T')+'Z')-Date.now()-30*86400000)<3000);
  let home=await ApiService.getHome();
  check('Published plan returned with actual meal and item',home.active_plan.title==='Plano vigente NI2-294' && home.active_plan.meals[0].items[0].quantity===100);
  const override=await fetch(base+'/api/v1/patient/home?patient_id=2&clinic_id=2',{headers:{Authorization:'Bearer '+auth.token}});
  const overrideBody=await override.json();
  check('Caller cannot override patient or tenant using query parameters',override.status===200 && overrideBody.patient_name==='Paciente Teste A' && overrideBody.active_plan.patient_id===1);
  const professionalRoute=await fetch(base+'/dashboard',{headers:{Authorization:'Bearer '+auth.token},redirect:'manual'});
  check('Patient bearer cannot access professional web session',professionalRoute.status===302 && professionalRoute.headers.get('location')==='/login');
  check('Newer draft excluded',home.active_plan.status==='published' && !home.active_plan.title.includes('Rascunho'));
  const water=await ApiService.logHydration(300);
  check('Authenticated hydration persisted by patient',water.total_water_today===300 && inspect().water[0].patient_id===1 && inspect().water[0].amount_ml===300);
  check('New request reads persisted hydration',(await ApiService.getHome()).water_ml_today===300);
  const ownToken=auth.token;
  await login('patient-b@example.test');
  home=await ApiService.getHome();
  check('Other tenant receives neither plan nor hydration',home.patient_name==='Paciente Teste B' && home.active_plan===null && home.water_ml_today===0);
  await ApiService.logout();
  await login('empty@example.test');
  check('Real empty plan is null rather than request failure',(await ApiService.getHome()).active_plan===null);
  await ApiService.logout();
  setAuthToken(ownToken);
  await ApiService.logout();
  check('Logout clears client token',getAuthToken()===null);
  check('Logout deletes server token',!inspect().tokens.some(t=>t.token===persisted.token));
  setAuthToken(ownToken);
  await rejection('Revoked token rejected',()=>ApiService.getHome(),401);
  let expiredEvents=0;
  setSessionExpiredHandler(()=>expiredEvents++);
  auth=await login(); fixture('expire');
  await rejection('Expired token rejected',()=>ApiService.getHome(),401);
  check('401 clears client session and dispatches expiry event',getAuthToken()===null && expiredEvents===1);
  await login(); fixture('null-expiry');
  await rejection('Token without mandatory expiry rejected',()=>ApiService.getHome(),401);
  auth=await login(); fixture('deactivate');
  await rejection('Existing token rejected after patient deactivation',()=>ApiService.getHome(),401);
  fixture('reactivate');
  await login(); fixture('clinic-off');
  await rejection('Existing token rejected after clinic deactivation',()=>ApiService.getHome(),401);
  const off=await rawLogin({email:'patient-a@example.test',password:'PacienteTeste@2026'});
  check('Inactive clinic cannot issue a token',off.status===401 && !off.body.token);
  fixture('clinic-on');
  await login(); fixture('wrong-type');
  await rejection('Non-patient token type rejected despite colliding id',()=>ApiService.getHome(),401);
  fixture('ambiguous');
  const ambiguous=await rawLogin({email:'patient-a@example.test',password:'PacienteTeste@2026'});
  check('Ambiguous identity across tenants fails closed',ambiguous.status===401 && !ambiguous.body.token);
  const after=inspect();
  check('Plan records and immutable published history preserved',JSON.stringify(before.plans)===JSON.stringify(after.plans) && before.snapshots===after.snapshots && after.snapshots>0);
  // Stop the real server to exercise transport failure, without replacing fetch.
  setAuthToken(auth.token);
  const stopped=new Promise(r=>server.once('exit',r)); server.kill(); await stopped;
  await rejection('Network outage produces real transport error',()=>ApiService.getHome(),0);
  await rejection('Logout during network outage reports failure',()=>ApiService.logout(),0);
  check('Failed revocation retains token for retry',getAuthToken()===auth.token);
  console.log(`${rows.length}/${rows.length} checks passed`);
})().catch(e=>{ rows.push({name:e.message,status:'FAIL'}); console.error(e); process.exitCode=1; }).finally(()=>{
  if(server && server.exitCode===null) server.kill();
  writeFileSync(path.join(root,'docs/validation/NI2-294/evidence/http-client-results.json'), JSON.stringify({ timestamp:new Date().toISOString(), runtime:process.version, transport:'actual TCP HTTP; production front controller; compiled mobile API; dedicated SQLite; synthetic fixtures', checks:rows },null,2));
  writeFileSync(path.join(root,'docs/validation/NI2-294/evidence/http-server.log'),serverLog);
  rmSync(dir,{recursive:true,force:true});
});
