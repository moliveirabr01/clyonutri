"""Real Chromium DOM, production food-picker.js, synthetic JSON transport; no server/auth assertion."""
import argparse, base64, html, json, os, pathlib, re, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(); parser.add_argument('--chromium', required=True); args = parser.parse_args()
payload = "Ataque `${alert(document.cookie)}` </script><img src=x onerror=alert(1)> 'aspas' \"duplas\" & \\barra"
script = base64.b64encode((root/'public/assets/js/food-picker.js').read_bytes()).decode()
fixture = json.dumps({'id': 897, 'name': payload, 'category': payload, 'is_custom': True, 'energy_kcal': 100})
fixture = fixture.replace('<', '\\u003c').replace('>', '\\u003e').replace('&', '\\u0026')
page = '''<!doctype html><html><body><form><div data-food-picker>
<input data-food-query><label data-food-results-label>Alimento</label><select></select>
<p role="status"></p><button data-food-next type="button">Próxima</button><button data-food-retry type="button">Repetir</button>
</div></form><pre id="result">RUNNING</pre><script>
window.executedPayloads=0;window.errors=[];window.alert=()=>window.executedPayloads++;
window.addEventListener('error',e=>window.errors.push(e.message));
const food=FIXTURE;
window.fetch=async()=>({ok:true,status:200,json:async()=>({data:[food],total:1,has_more:false})});
eval(atob('SCRIPT'));
(async()=>{
const checks=[];const ck=(name,ok)=>{checks.push({name,ok});if(!ok)throw Error(name);};
try {
const box=document.querySelector('[data-food-picker]'),query=box.querySelector('input');
query.focus();await new Promise(r=>setTimeout(r,100));
const result=box.querySelector('button.food-result');
ck('nome do resultado é texto literal',result.querySelector('strong').textContent===food.name);
result.click();await new Promise(r=>setTimeout(r,100));
const select=box.querySelector('select');
ck('Option nativa mantém nome literal',select.selectedOptions[0].text===food.name&&select.value==='897');
ck('seleção mantém nome literal',box.querySelector('.food-selection').textContent.includes(food.name));
ck('payload não cria elementos nem atributos executáveis',!document.querySelector('img,svg,iframe,[onerror],[onload]'));
ck('payload não executa alert nem gera erro JS',window.executedPayloads===0&&window.errors.length===0);
document.body.dataset.result='PASS';
} catch(e) {document.body.dataset.result='FAIL';checks.push({error:e.message});}
document.getElementById('result').textContent=JSON.stringify(checks);
})();</script></body></html>'''.replace('FIXTURE', fixture).replace('SCRIPT', script)
with tempfile.TemporaryDirectory(prefix='ni2-897-dom-',dir='/tmp') as work:
    p=pathlib.Path(work)/'test.html';p.write_text(page)
    proc=subprocess.run([args.chromium,'--headless','--no-sandbox','--disable-gpu','--no-first-run','--no-default-browser-check','--user-data-dir='+work+'/profile','--virtual-time-budget=3000','--dump-dom',p.as_uri()],capture_output=True,text=True,timeout=20)
    if proc.returncode:raise SystemExit('BLOCKED Chromium launch: '+proc.stderr[-1600:])
    match=re.search(r'<pre id="result">(.*?)</pre>',proc.stdout,re.S)
    if match:print(html.unescape(match.group(1)))
    if 'data-result="PASS"' not in proc.stdout:raise SystemExit('FAIL: Chromium did not report PASS; '+proc.stderr[-400:])
    print('PASS Chromium DOM: production picker, 5 checks; synthetic transport, no HTTP/auth claim')
