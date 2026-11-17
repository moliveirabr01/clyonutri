"""Real front controller via PHP-CGI; verifies CGI HTTP headers, not TCP/browser navigation."""
import os, json, pathlib, re, shutil, subprocess, tempfile, urllib.parse, sqlite3
root=pathlib.Path(__file__).resolve().parents[1]
if not shutil.which('php-cgi'): raise SystemExit('BLOCKED: php-cgi indisponível')
with tempfile.TemporaryDirectory(prefix='ni2-868-cgi-',dir='/tmp') as work:
    env=os.environ.copy();env.update(APP_ENV='testing',APP_DEBUG='false',APP_URL='https://localhost',APP_TIMEZONE='America/Sao_Paulo',DB_CONNECTION='sqlite',DB_DATABASE=work+'/test.sqlite',V54_DB=work+'/test.sqlite',CLINICAL_ENCRYPTION_KEY='test-clinical-key-v5445-not-for-prod',TEST_CGI_SESSION_DIR=work,LEGAL_REACCEPTANCE_ENABLED='1')
    setup=subprocess.run(['php','tests/support/ni2_868_cgi_fixture.php'],cwd=root,env=env,capture_output=True,text=True,check=True)
    fixture=json.loads(setup.stdout);cookies={}
    def request(path, data=None):
        body=urllib.parse.urlencode(data).encode() if data is not None else b''
        req=env.copy();req.update(REQUEST_METHOD='POST' if data is not None else 'GET',REQUEST_URI=path,QUERY_STRING=urllib.parse.urlsplit(path).query,SCRIPT_FILENAME=str(root/'tests/support/isolated_cgi_front.php'),SCRIPT_NAME='/index.php',REDIRECT_STATUS='200',SERVER_NAME='localhost',SERVER_PORT='443',SERVER_PROTOCOL='HTTP/1.1',HTTP_HOST='localhost',HTTPS='on',REMOTE_ADDR='127.0.0.1',CONTENT_TYPE='application/x-www-form-urlencoded',CONTENT_LENGTH=str(len(body)),HTTP_COOKIE='; '.join(f'{k}={v}' for k,v in cookies.items()))
        result=subprocess.run(['php-cgi','-d','cgi.force_redirect=0'],cwd=root,env=req,input=body,capture_output=True,check=True)
        raw=result.stdout.decode();head,html=re.split(r'\r?\n\r?\n',raw,maxsplit=1);headers={}
        for line in head.splitlines():
            key,sep,value=line.partition(':')
            if sep:
                headers[key.lower()]=value.strip()
                if key.lower()=='set-cookie':
                    k,v=value.strip().split(';',1)[0].split('=',1);cookies[k]=v
        status=int(headers.get('status','200').split()[0]);return status,headers,html
    code,headers,html=request('/login');assert code==200,(code,headers)
    token=re.search(r'name="_csrf_token" value="([^"]+)"',html).group(1)
    code,headers,html=request('/login',{'email':'cgi868@example.test','password':'SenhaCGITest@2026','_csrf_token':token})
    assert code in [302,303] and headers.get('location')=='/dashboard',(code,headers,html[:200])
    print('PASS CGI: login real e sessão persistente')
    for case in fixture['appointments']:
        page='/agenda?view='+case['view']+'&date='+fixture['date'];code,headers,html=request(page)
        assert code==200,(code,headers,html[:150]);token=re.search(r'name="_csrf_token" value="([^"]+)"',html).group(1)
        code,headers,_=request('/agenda/'+str(case['id'])+'/status',{'_csrf_token':token,'status':case['status'],'view':case['view'],'date':fixture['date']})
        expected=page+'&status_updated=1';assert code in [302,303] and headers.get('location')==expected,(case,code,headers)
        db=sqlite3.connect(env['DB_DATABASE']);saved=db.execute('SELECT status FROM appointments WHERE id=?',(case['id'],)).fetchone()[0];db.close();assert saved==case['status']
        print('PASS CGI:',case['view'],case['status'],'redirect + persistência')
