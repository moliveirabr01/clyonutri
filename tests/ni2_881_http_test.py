"""Real HTTP regression; synthetic SQLite, no development data or .env changes."""
import json, os, pathlib, subprocess, tempfile, time, urllib.request, urllib.error
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='ni2-95-881-', dir='/tmp') as scratch:
    env=os.environ.copy()
    env.update(DB_CONNECTION='sqlite', DB_DATABASE=scratch+'/test.sqlite', APP_ENV='testing', APP_DEBUG='false', APP_URL='http://127.0.0.1:18081', CLYONUTRI_PORT='18081', CLINICAL_ENCRYPTION_KEY='test-clinical-key-v5445-not-for-prod', APP_TIMEZONE='America/Sao_Paulo')
    subprocess.run(['php','tests/support/ni2_881_http_fixture.php'],cwd=root,env=env,check=True,stdout=subprocess.DEVNULL)
    with open(scratch+'/server.log','w') as log:
        server=subprocess.Popen(['./bin/serve-local.sh'],cwd=root,env=env,stdout=log,stderr=log)
        def request(path, token=None, data=None):
            headers={'Content-Type':'application/json'}
            if token: headers['Authorization']='Bearer '+token
            req=urllib.request.Request('http://127.0.0.1:18081/api/v1/'+path, data=json.dumps(data).encode() if data else None, headers=headers)
            try:
                with urllib.request.urlopen(req) as response: return response.status,json.load(response)
            except urllib.error.HTTPError as e: return e.code,json.load(e)
        try:
            for _ in range(50):
                if server.poll() is not None: raise RuntimeError('Servidor não iniciou: '+pathlib.Path(scratch+'/server.log').read_text())
                try: request('patient/home'); break
                except urllib.error.URLError: time.sleep(.1)
            status,_=request('patient/prescriptions'); assert status==401; print('PASS Lista de prescrições exige autenticação: 401')
            def login(email):
                status,body=request('auth/patient-login',data={'email':email,'password':'PacienteTeste@2026'})
                assert status==200,(status,body)
                return body['token']
            ana=login('ana95@example.test')
            status,body=request('patient/prescriptions',ana)
            assert status==200 and len(body['prescriptions'])==1,(status,body)
            print('PASS GET com catálogo validado preserva lista')
            carla=login('carla95@example.test')
            status,body=request('patient/prescriptions',carla)
            assert status==200 and body['prescriptions']==[],(status,body)
            print('PASS GET sem catálogo devolve lista vazia mesmo havendo histórico')
            bruno=login('bruno95@example.test')
            status,body=request('patient/prescriptions',bruno)
            assert status==200 and body['prescriptions']==[],(status,body)
            print('PASS Paciente da mesma clínica não recebe a prescrição de outra pessoa')
        finally:
            server.terminate();server.wait(timeout=10)
