# NI2-91: evidências das correções (21/10/2026)

Ver `code-review-NI2-91.md` (raiz do repositório), seção "Status das correções".

| Arquivo | Conteúdo |
|---|---|
| `antes.log`, `antes-resultados.json` | Teste real contra a árvore anterior (`72d703a`): 1 PASS, 7 FAIL |
| `depois.log`, `depois-resultados.json` | SQLite: 34 PASS, 0 FAIL |
| `depois-mysql8.log`, `depois-mysql8-resultados.json` | MySQL 8.0.46: 34 PASS, 0 FAIL |
| `provas-depois.txt` | `tests/review_ni2_91_provas.php` depois das correções. ACHADO só em P2, P7 e P8, que dependem de decisão jurídica ou de produto |
| `regressao-suites.txt` | 97 suítes PHP: 78 PASS. Nenhuma falha nova; as 19 falhas são antigas |
| `fila-pedidos-1366.png`, `fila-pedidos-390.png` | Fila `/privacidade/pedidos` com dados fictícios |

**Como reproduzir:**

```bash
node tests/ni2_91_privacy_real_test.cjs
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_USERNAME=… DB_PASSWORD=… node tests/ni2_91_privacy_real_test.cjs
```

**Não testado:**
- MySQL 5.7 da HostGator (não há SQL novo);
- app em aparelho real;
- validação independente.
