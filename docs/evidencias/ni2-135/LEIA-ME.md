# NI2-135: evidências

Ver `code-review-NI2-135.md` (raiz do repositório), seções "Status das correções".

| Arquivo | Conteúdo |
|---|---|
| `antes.log`, `depois.log` (+ `-resultados.json`) | Anotações internas: 6/9 → 9/9 |
| `evolucao-antes.log`, `evolucao-depois.log` (+ json) | Evolução completa, SQLite: árvore anterior x 52/52 |
| `evolucao-mysql8.log` (+ json) | Evolução completa, MySQL 8.0.46: 52/52 |
| `app-web.log`, `app-web-resultados.json` | App (build web) no Chromium: 14/14 |
| `app-evolucao-390.png`, `app-evolucao-390-apos-registro.png`, `app-evolucao-1024.png` | Aba Evolução com meta, gráfico, filtro e registro |
| `perfil-profissional-evolucao-app.png` | Seção do profissional: liberar medidas, autorrelatos e metas |
| `provas-depois.txt` | `review_ni2_135_provas.cjs` depois das correções: tudo "ok" |
| `mysql-schema-gate.json` | Gate de schema MySQL: instalação limpa, upgrade e SQL de instalação |
| `regressao-suites.txt` | 97 suítes PHP |

**HostGator:**
1. Rodar `database/manual/preflight-2026-10-21-evolucao-ni2-135.sql`.
2. Rodar `database/manual/migracao-2026-10-21-evolucao-ni2-135.sql`.
3. Subir os arquivos.
4. Liberar as medidas de cada paciente no perfil.
