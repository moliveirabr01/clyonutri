'use strict';
const items=document.getElementById('rx-items');
if(items){
 let next=items.children.length;
 document.getElementById('rx-add').addEventListener('click',()=>{
  if(items.children.length>=20)return;
  const row=items.firstElementChild.cloneNode(true);
  row.querySelectorAll('[name]').forEach(el=>{el.name=el.name.replace(/items\[\d+\]/,`items[${next}]`);el.value='';});
  next++;items.appendChild(row);row.querySelector('select').focus();
 });
 items.addEventListener('click',e=>{if(e.target.closest('[data-remove-item]')&&items.children.length>1)e.target.closest('.rx-item').remove();});
}
