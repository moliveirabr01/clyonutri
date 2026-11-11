/** Repeatable local verification; never runs against production or sends external messages. */
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),net=require('node:net'),cp=require('node:child_process'),crypto=require('node:crypto');
const mobile=path.resolve(__dirname,'..'),repo=path.resolve(mobile,'..'),backend=process.env.PATIENT_BACKEND_ROOT;
if(!backend)throw Error('Set PATIENT_BACKEND_ROOT to the v54.32 release directory.');
const evidence=path.join(repo,'docs/delivery/patient-mobile-reference/evidence');
fs.mkdirSync(evidence,{recursive:true});const work=fs.mkdtempSync('/tmp/clyonutri-patient-ds-test-');
const npm=process.platform==='win32'?'npm.cmd':'npm';let server;
const build=JSON.parse(cp.execFileSync('php',[path.join(backend,'bin/build_identity.php'),'--verify'],{encoding:'utf8'}));
if(build.integrity!=='consistent'||build.package_version!=='v54.32')throw Error('Verified v54.32 backend required.');
function run(command,args,env,log){const r=cp.spawnSync(command,args,{cwd:mobile,env,encoding:'utf8',maxBuffer:12*1024*1024});fs.writeFileSync(path.join(evidence,log),(r.stdout||'')+(r.stderr||''));if(r.status!==0)throw Error(command+' failed; see '+log);}
(async()=>{try{
 const sock=net.createServer();await new Promise(r=>sock.listen(0,'127.0.0.1',r));const port=sock.address().port;await new Promise(r=>sock.close(r));
 const base='http://127.0.0.1:'+port;
 const env={...process.env,PATIENT_BACKEND_ROOT:backend,PATIENT_FIXTURE:work+'/fixture.json',PATIENT_TEST_URL:base,PATIENT_EVIDENCE:evidence,
  ADAPTIVE_TEST_DSN:'sqlite:'+work+'/test.sqlite',ADAPTIVE_FIXTURE_FILE:work+'/fixture.json',
  APP_ENV:'testing',FORCE_HTTPS:'false',DB_CONNECTION:'sqlite',DB_DATABASE:work+'/test.sqlite',APP_URL:base,
  APP_KEY:'patient-reference-synthetic-key-000000000',APP_TIMEZONE:'America/Sao_Paulo',EXPO_NO_TELEMETRY:'1',EXPO_OFFLINE:'1',CI:'1'};
 run('php',['tests/patient-reference-fixture.php'],env,'fixture.log');
 run(npm,['run','typecheck'],env,'typecheck.log');
 run(npm,['run','build:api-test'],env,'api-build.log');
 run(path.join(mobile,'node_modules/.bin/expo'),['export','--platform','web','--output-dir','dist-patient-reference'],env,'web-export.log');
 const log=fs.openSync(work+'/server.log','w');
 server=cp.spawn('php',['-S','127.0.0.1:'+port,'-t','dist-patient-reference','tests/patient-reference-router.php'],{cwd:mobile,env,stdio:['ignore',log,log]});
 for(let i=0;i<60;i++){try{const r=await fetch(base);if(r.ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
 run(process.execPath,['tests/patient-reference-api.cjs'],env,'api-client.log');
 run(process.execPath,['tests/patient-reference-ui.cjs'],env,'ui.log');
 run(path.join(mobile,'node_modules/.bin/expo'),['export','--platform','android','--platform','ios','--output-dir',work+'/native'],env,'native-export.log');
 const after=JSON.parse(cp.execFileSync('php',[path.join(backend,'bin/build_identity.php'),'--verify'],{encoding:'utf8'}));
 if(JSON.stringify(build)!==JSON.stringify(after))throw Error('Backend changed during verification.');
 const files=[];for(const dir of ['src','tests']){const walk=p=>{for(const f of fs.readdirSync(p,{withFileTypes:true})){const q=path.join(p,f.name);if(f.isDirectory())walk(q);else files.push(q);}};walk(path.join(mobile,dir));}
 for(const name of ['App.tsx','package.json','package-lock.json','app.json','eas.json'])files.push(path.join(mobile,name));
 fs.writeFileSync(path.join(evidence,'run.json'),JSON.stringify({at_utc:new Date().toISOString(),technical_result:'PASS',backend:build,api:JSON.parse(fs.readFileSync(evidence+'/api-native-client.json')),ui:{checks:JSON.parse(fs.readFileSync(evidence+'/ui-report.json')).checks.length,states:JSON.parse(fs.readFileSync(evidence+'/ui-report.json')).states.length},native_exports:['android Hermes JS','ios Hermes JS'],physical_devices_tested:false,app_store_publication:false,
 source_files:files.sort().map(file=>({path:path.relative(mobile,file),sha256:crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex')}))},null,2));
 console.log('Patient reference: PASS; backend v54.32 unchanged; evidence '+evidence);
}finally{if(server){server.kill();await new Promise(r=>server.once('exit',r));}fs.rmSync(work,{recursive:true,force:true});}})().catch(e=>{console.error(e.message);process.exitCode=1});
