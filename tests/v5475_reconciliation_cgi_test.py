"""Real front controller via PHP-CGI; verifies CGI HTTP headers, not TCP/browser navigation."""
import os, json, pathlib, re, shutil, subprocess, tempfile, urllib.parse, sqlite3
root=pathlib.Path(__file__).resolve().parents[1]
if not shutil.which('php-cgi'): raise SystemExit('BLOCKED: php-cgi indisponível')
with tempfile.TemporaryDirectory(prefix='ni2-868-cgi-',dir='/tmp') as work:
    env=os.environ.copy();env.update(APP_ENV='testing',APP_DEBUG='false',APP_URL='https://localhost',APP_TIMEZONE='America/Sao_Paulo',DB_CONNECTION='sqlite',DB_DATABASE=work+'/test.sqlite',V54_DB=work+'/test.sqlite',CLINICAL_ENCRYPTION_KEY='test-clinical-key-v5445-not-for-prod',TEST_CGI_SESSION_DIR=work,LEGAL_REACCEPTANCE_ENABLED='1',MFA_PLATFORM_REQUIRED='false',MAIL_DRIVER='null')
    setup=subprocess.run(['php','tests/support/v5475_cgi_fixture.php'],cwd=root,env=env,capture_output=True,text=True,check=True)
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
    def token(html): return re.search(r'name="_csrf_token" value="([^"]+)"',html).group(1)
    def login(email):
        cookies.clear()
        code,h,html=request('/login');assert code==200
        return request('/login',{'email':email,'password':'SenhaCGITest@2026','_csrf_token':token(html)})
    code,h,html=login('admin75@example.test');assert h.get('location')=='/admin',(code,h)
    for path in ['/login','/dashboard']:
        code,h,html=request(path);assert h.get('location') in ['/admin','/admin/dashboard'],(path,code,h)
    code,h,html=request('/admin/dashboard');assert code==200,(code,h,html[:100])
    print('PASS CGI: administrador entra sem loop com MFA suspenso e navega ao console')
    code,h,html=request('/pacientes');assert code==403
    print('PASS CGI: administrador continua sem acesso clínico')
    code,h,html=login('orphan75@example.test');assert h.get('location')=='/login'
    code,h,html=request('/login');assert code==200 and 'não está vinculado' in html
    print('PASS CGI: usuário sem clínica recebe erro legível sem sessão quebrada')
    code,h,html=login('cgi868@example.test');assert h.get('location')=='/dashboard'
    code,h,html=request('/admin/dashboard');assert code==403
    print('PASS CGI: profissional continua sem acesso ao console')
    # Turn MFA on after a real authenticated session to exercise the historical session step-up.
    login('admin75@example.test');env['MFA_PLATFORM_REQUIRED']='true'
    code,h,html=request('/admin/dashboard');assert h.get('location')=='/login/mfa'
    code,h,html=request('/login/mfa');assert code==200 and 'mfa-setup-secret' in html
    print('PASS CGI: sessão anterior é encaminhada ao cadastro MFA sem loop')
    secret=re.search(r'data-testid="mfa-setup-secret">([^<]+)',html).group(1)
    import base64,hmac,hashlib,time,struct
    key=base64.b32decode(secret+'='*((8-len(secret)%8)%8));digest=hmac.new(key,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();offset=digest[-1]&15
    totp=str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
    csrf=token(html)
    code,h,_=request('/login/mfa',{'code':totp,'_csrf_token':'invalid'});assert code==403
    code,h,_=request('/login/mfa',{'code':totp,'_csrf_token':csrf});assert h.get('location')=='/login/mfa/codigos',(code,h)
    code,h,html=request('/login/mfa/codigos');assert code==200 and len(re.findall(r'<li><code',html))==8
    csrf=token(html)
    code,h,_=request('/login/mfa/codigos',{'saved':'1','_csrf_token':'invalid'});assert code==403,(code,h)
    code,h,_=request('/login/mfa/codigos',{'saved':'1','_csrf_token':csrf});assert h.get('location') in ['/admin','/admin/dashboard']
    code,h,html=request('/login/mfa/codigos');assert code in [302,303] and 'mfa-recovery-card' not in html
    db=sqlite3.connect(env['DB_DATABASE']);assert db.execute("SELECT COUNT(*) FROM audit_logs WHERE action='AUTH_MFA_RECOVERY_CODES_ACKNOWLEDGED'").fetchone()[0]==1;db.close()
    print('PASS CGI: TOTP válido, oito códigos, CSRF, confirmação única e auditoria persistida')
