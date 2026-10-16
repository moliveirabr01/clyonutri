import re,json,statistics
txt=open('taco.txt',encoding='utf-8').read()
a=txt.index('Tabela 1. Composição'); b=txt.index('Tabela 2. Composição')
pages=txt[a:b].split('\f')
groups=["Cereais e derivados","Verduras, hortaliças e derivados","Frutas e derivados","Gorduras e óleos","Pescados e frutos do mar","Carnes e derivados","Leite e derivados","Bebidas (alcoólicas e não alcoólicas)","Ovos e derivados","Produtos açucarados","Miscelâneas","Outros alimentos industrializados","Alimentos preparados","Leguminosas e derivados","Nozes e sementes"]
T=r'(?:\d+(?:,\d+)?|Tr|NA|\*)'
rx=re.compile(r'^\s*(\d{1,3})\s{2,}(\S.*?)((?:\s+'+T+r'){8,11})\s*$')
out={};grp=None;fixed=[]
for p in pages:
    rows=[]
    for s in p.split('\n'):
        if s.strip() in groups: grp=s.strip()
        m=rx.match(s)
        if m:
            toks=[(t.group(),t.end()+m.start(3)) for t in re.finditer(r'\S+',m.group(3))]
            rows.append((int(m.group(1)),re.sub(r'\s+',' ',m.group(2)).strip(),grp,toks))
    full=[r[3] for r in rows if len(r[3])==11]
    if not rows: continue
    if full:
        cols=[statistics.median(t[i][1] for t in full) for i in range(11)]
    for n,name,g,toks in rows:
        if n in out: continue
        if len(toks)==11: vals=[t[0] for t in toks]
        else:
            vals=['']*11
            for t,e in toks:
                i=min(range(11),key=lambda k:abs(cols[k]-e)); assert vals[i]=='',(n,toks); vals[i]=t
            fixed.append((n,name,vals))
        out[n]=dict(code=n,name=name,group=g,vals=vals)
print(len(out))
for f in fixed: print(f)
json.dump(out,open('t1.json','w'),ensure_ascii=False,indent=0)
