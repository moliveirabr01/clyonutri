# uso: python3 shots412.py <porta> <dir-saida>  — dados demo/fictícios (clínica demo 1)
import sys, json, re
from playwright.sync_api import sync_playwright
port, out = sys.argv[1], sys.argv[2]
B = f"http://localhost:{port}"
VPS = {"360": (360, 740, 1), "390": (390, 844, 1), "768": (768, 1024, 1), "1366": (1366, 768, 1), "zoom200": (683, 384, 2)}
PAGES = [
 ("login", "/login", None), ("dashboard", "/dashboard", None),
 ("agenda-semana", "/agenda?view=week&date=2026-10-14", None),
 ("pacientes-modal", "/pacientes", "patient"), ("perfil", "/pacientes/1", None),
 ("anamnese", "/pacientes/1/anamnese", None), ("planos-modal", "/planos", "plan"),
 ("editor-busca-arroz", "/planos/9547", "food"), ("chat", "/chat", None), ("cadastro", "/cadastro", None),
]
JS_METRICS = """() => {
 const vis = e => e.getClientRects().length && getComputedStyle(e).visibility!=='hidden';
 const unnamed = [...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(vis).filter(e => !(e.labels&&e.labels.length) && !e.getAttribute('aria-label') && !e.getAttribute('aria-labelledby') && !e.title).map(e=>e.name||e.id||e.type);
 return {hscroll: document.documentElement.scrollWidth > document.documentElement.clientWidth+1, scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth, unnamed};
}"""
def login(page):
    page.goto(B + "/login"); page.fill("input[name=email]", "camila@clyonutri.com.br"); page.fill("input[name=password]", "SenhaDemo123")
    page.click("button[type=submit]"); page.wait_for_load_state()
def act(page, kind):
    try:
        if kind == "patient":
            page.get_by_role("button", name=re.compile("Cadastrar Paciente", re.I)).first.click()
        elif kind == "plan":
            page.get_by_role("button", name=re.compile("Novo Plano", re.I)).first.click()
        elif kind == "food":
            page.locator("button", has_text="+ Alimento").first.click(); page.wait_for_timeout(300)
            q = page.locator("[data-food-query]:visible").first; q.fill("arroz"); page.wait_for_timeout(1200)
        page.wait_for_timeout(400)
    except Exception as e:
        return f"acao-falhou: {e.__class__.__name__}"
    return "ok"
res = {}
with sync_playwright() as p:
    br = p.chromium.launch(executable_path="/usr/bin/google-chrome")
    for vk, (w, h, dsf) in VPS.items():
        ctx = br.new_context(viewport={"width": w, "height": h}, device_scale_factor=dsf, locale="pt-BR")
        page = ctx.new_page(); login(page)
        for name, url, kind in PAGES:
            if name in ("login", "cadastro"):
                c2 = br.new_context(viewport={"width": w, "height": h}, device_scale_factor=dsf); pg = c2.new_page(); pg.goto(B + url)
            else:
                pg = page; pg.goto(B + url)
            a = act(pg, kind) if kind else "-"
            m = pg.evaluate(JS_METRICS); m["acao"] = a; m["status_url"] = pg.url
            pg.screenshot(path=f"{out}/{name}_{vk}.png", full_page=False)
            res[f"{name}_{vk}"] = m
            if pg is not page: c2.close()
        ctx.close()
    br.close()
json.dump(res, open(f"{out}/metricas.json", "w"), indent=1, ensure_ascii=False)
print("ok", len(res))
