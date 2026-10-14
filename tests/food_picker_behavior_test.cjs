const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
class Element {
  constructor() { this.children=[]; this.events={}; this.value=''; this.hidden=false; this.attributes={}; this.options=[]; }
  setAttribute(key,value) { this.attributes[key]=value; }
  addEventListener(key,fn) { this.events[key]=fn; }
  append(...children) { this.children.push(...children); }
  replaceChildren(...children) { this.children=children; this.options=children; this.value=''; }
  add(option) { this.options.push(option); }
  before() {}
  focus() { this.focused=true; }
  setCustomValidity(message) { this.validation=message; }
  get firstElementChild() { return this.children[0]; }
}
const input=new Element(), select=new Element(), status=new Element(), next=new Element(), retry=new Element(), label=new Element();
const fields={'[data-food-query]':input,select,'[role=status]':status,'[data-food-next]':next,'[data-food-retry]':retry,'[data-food-results-label]':label};
const box=new Element(); box.querySelector=q=>fields[q];
const created=[]; let response={data:[{id:7,name:'Ovo cozido',energy_kcal:146}],has_more:false};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../public/assets/js/food-picker.js'),'utf8'), {
 document:{querySelectorAll:q=>q==='[data-food-picker]'?[box]:[],createElement:()=>{const e=new Element();created.push(e);return e;}},
 window:{},Option:class {constructor(text,value){this.text=text;this.value=value;}},AbortController,
 fetch:async()=>({ok:true,json:async()=>response}),setTimeout:fn=>{fn();return 1;},clearTimeout(){}
});
(async()=>{
 await input.events.focus(); await new Promise(setImmediate);
 assert.equal(next.hidden,true);assert.equal(retry.hidden,true);assert.match(status.textContent,/1 alimento encontrado\./);
 const results=created[0], chosen=created[1];assert.equal(results.children.length,1);
 results.children[0].events.click();assert.equal(select.value,'7');assert.equal(input.validation,'');assert.equal(chosen.hidden,false);
 input.events.keydown({key:'ArrowDown',preventDefault(){}});assert.equal(results.children[0].focused,true);
 response={data:[],has_more:false};input.events.input();await new Promise(setImmediate);
 assert.equal(select.value,'');assert.ok(input.validation);assert.equal(chosen.hidden,true);assert.equal(results.children.length,0);assert.match(status.textContent,/Nenhum alimento/);
 console.log('PASS food picker: clickable selection, form validation, keyboard access, singular count, empty search and conditional buttons.');
})().catch(e=>{console.error(e);process.exitCode=1;});
