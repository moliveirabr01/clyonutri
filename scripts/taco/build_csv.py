import json,csv,re,hashlib
d=json.load(open('t1.json'))
b472=json.load(open('br/472.json'))
d['69']['name']='Abóbora, pescoço, crua'; d['472']['name']='Cana, aguardente 1'; d['472']['group']='Bebidas (alcoólicas e não alcoólicas)'; d['472']['vals']=['', '216','902','','','NA','','NA','','Tr','Tr']  # linha com células em branco; posições conferidas com o PDF e brolesi
COLS=[('energy_kcal',1),('protein_g',3),('lipid_g',4),('carbohydrate_g',6),('fiber_g',7)]
rows=[];skipped=[]
for k in sorted(d,key=int):
    v=d[k];name=re.sub(r'\s+[12]$','',v['name'])
    out={'taco_code':int(k),'name':name,'category':v['group']};flags=[];unknown_core=False
    for c,i in COLS:
        r=v['vals'][i]
        if r in('Tr','NA'): out[c]='0'; flags.append(f'{c}={r}')
        elif r in('*',''):
            out[c]='0'; flags.append(f"{c}={'*' if r=='*' else 'em_branco'}")
            if c!='fiber_g': unknown_core=True
        else: out[c]=r.replace(',','.')
    out['nutrient_flags']=';'.join(flags)
    if unknown_core and v['vals'][1] in('*',''): skipped.append((k,name)); out['import']=0
    else: out['import']=1
    rows.append(out)
f=open('/workspace/clyonutri/build-src/database/seeds/taco/taco_4a_edicao_tabela1.csv','w',newline='',encoding='utf-8')
w=csv.DictWriter(f,fieldnames=list(rows[0].keys()),lineterminator='\n');w.writeheader();w.writerows(rows);f.close()
print(len(rows),sum(r['import'] for r in rows),skipped)
print([ (r['taco_code'],r['name'],r['nutrient_flags']) for r in rows if 'em_branco' in r['nutrient_flags'] or ('=*' in r['nutrient_flags'] and r['import'])])
