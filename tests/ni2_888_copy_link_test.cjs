'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const script = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/agenda-configuration.js'), 'utf8');
async function run(allow, path) {
  let handler, copied, focused=false, selected=false;
  const status={textContent:''};
  const input={value:'',focus(){focused=true},select(){selected=true}};
  const fallback={hidden:true,querySelector(){return input}};
  const root={querySelector(selector){return selector.includes('status')?status:fallback}};
  const button={dataset:{bookingUrl:path},closest(){return root},addEventListener(type,fn){handler=fn}};
  vm.runInNewContext(script,{URL,window:{location:{origin:'https://consultorio.example.test'}},document:{querySelectorAll(){return [button]}},navigator:allow?{clipboard:{async writeText(value){copied=value}}}:{}});
  await handler();
  if(allow){assert.equal(copied,'https://consultorio.example.test/agendar/nutri-teste');assert.equal(status.textContent,'Link copiado.');assert.equal(fallback.hidden,true)}
  else{assert.equal(fallback.hidden,false);assert.equal(input.value,'https://consultorio.example.test/agendar/nutri-teste');assert.ok(focused&&selected);assert.match(status.textContent,/copie/)}
}
(async()=>{await run(true,'/agendar/nutri-teste');await run(false,'/agendar/nutri-teste');await run(true,'https://consultorio.example.test/agendar/nutri-teste');console.log('3 PASS: clipboard, fallback acessível e URL absoluta');})().catch(e=>{console.error(e);process.exit(1)});
