# Inventário de migração para Laravel 13 — ClyoNutri (NI2)

**Data:** 07/10/2026 (BRT) · **Para:** Maicon Oliveira · **Elaborado por:** Grok Bot
**Base:** snapshot só leitura do repositório local `Nutri_II` (`HEAD 0a136f6`, 11 commits, sem remote) + Jira NI2 lido em 07/10/2026 ~22h BRT (nada foi alterado no Jira).
**Decisão de partida (do Maicon):** novo projeto Laravel 13 (PHP 8.3+, MySQL 8, Blade, Sanctum para o app React Native), reaproveitando o que for bom do MVC próprio.
**Hospedagem da fase 1 (decisão do Maicon, v1.1):** HostGator Brasil compartilhada (cPanel), PHP 8.3. Restrições e operação na seção 6; critérios de saída na seção 11.

### Histórico de versões

| Versão | Data | Mudança |
|---|---|---|
| v1.0 | 07/10/2026 | Inventário inicial: Jira × código, classificação, mapeamento Core→Laravel 13 e plano. |
| v1.1 | 07/10/2026 | **v1.1 — alvo HostGator compartilhada.** Nova seção 6 (restrições pesquisadas, deploy, fila via scheduler, backups, e-mail, MySQL); nova seção 11 (quando sair); riscos, Core→Laravel, decisões de dados, DoD, recomendações Jira e notas das histórias dependentes de infraestrutura atualizados; fontes na seção 13. Seções renumeradas a partir da 6. |

> Planilha com uma linha por história: `inventario-migracao-laravel.xlsx`. Provas de conceito reproduzíveis: `poc_modulos.php` (isolamento/regras por módulo) e `poc_ni2_6.php` (prontuário).

---

## 1. Resumo executivo

- **Escopo lido no Jira:** 15 épicos (NI2-1 a NI2-14 e NI2-112), **106 histórias** (NI2-35 a NI2-111, NI2-113 a NI2-130 e as novas NI2-131 a NI2-141) e 20 duplicadas (NI2-15 a NI2-34 = NI2-35 a NI2-54).
- **Estado real das 106 histórias:** **1 Implementado**, **64 Parcial**, **7 Só schema**, **34 Inexistente**.
- Das 64 "Parcial", quase todas são **só serviço de domínio, sem rota, tela ou API**. Apenas 6 histórias têm alguma superfície acessível hoje: login (NI2-41), shell e tokens (NI2-45, NI2-47), lista de pacientes (NI2-56), lista de alimentos (NI2-73) e dashboard (NI2-85).
- **Jira × realidade:** 95 histórias estão como "Feito". Só NI2-39 (remover Med/Psi/Fit) está de fato implementada, e foi trivial porque a base nasceu limpa.
- **Classificação das histórias para a migração:** 10 REAPROVEITAR, 45 MIGRAR, 11 REESCREVER, 3 DESCARTAR, 37 NOVO. (NOVO = não há código a aproveitar; construir direto em Laravel.)
- **Módulos/artefatos de código (49):** 9 REAPROVEITAR, 20 MIGRAR, 12 REESCREVER, 8 DESCARTAR.
- **O que vale levar:** o modelo de dados (como ponto de partida, com correções), regras puras (IMC/RCQ, agregação da lista de compras, saldo parcial/total do financeiro, mapeamento de CSV), os tokens e o markup do Design System Premium, o inventário legado e as specs.
- **Hospedagem fase 1 (v1.1): HostGator compartilhada.** Atende o MVP Blade + API Sanctum, desde que: o servidor da conta tenha **MySQL 8.0** (há servidores compartilhados com MySQL 5.7); a fila `database` seja drenada pelo scheduler a cada **15 min** (cron mínimo da HostGator, sem worker permanente); chat e app usem polling + push (sem websockets); o e-mail saia por provedor transacional; os backups sejam próprios e externos; e `vendor/` e os assets venham prontos do CI (não há Node no servidor). Seções 6 e 11.
- **O que não levar:** Core próprio (router, auth, CSRF, migrator, tenancy estática), testes com `assert()`, o cálculo nutricional atual (errado) e todo código **simulado**: IA de plano, resumo SOAP, teleconsulta, consentimento automático, verificação automática e métricas fixas no dashboard.

### Principais riscos

| Sev. | Risco | Evidência / mitigação |
|---|---|---|
| Crítico | Planejamento baseado em status falso | 95 histórias 'Feito' sem entrega funcional; NI2-11 está 'A fazer' com filhas 86–91 'Feito'. Reabrir antes de planejar sprints. |
| Crítico | Isolamento entre tenants só na leitura | PoC `poc_modulos.php`: 12 serviços gravaram dados no paciente/recurso de outro tenant (plano, financeiro, antropometria, hidratação, check-in, diário, agenda, chat, perfil público, teleconsulta, pacotes, health sync). No Laravel o isolamento precisa ser by-design (escopo global + validação de posse + FK composta). |
| Alto | Cálculo nutricional incorreto | Base de porção ambígua: 30 g de aveia viram 394 kcal (esperado ~118). Se for portado, gera prescrição errada. Reescrever NutritionCalculator com testes. |
| Alto | Código simulado que parece funcional | IA de plano (alimentos fixos), resumo SOAP com texto clínico inventado, URL de teleconsulta fictícia, consentimento LGPD criado automaticamente na importação, perfil público e conta já 'verificados', métricas 88,5% e '2 planos' fixas. |
| Alto | Modelo de dados com CASCADE e sem versionamento real | Apagar paciente apaga prontuário, planos e financeiro; planos publicados não versionam; anamnese não é imutável. Decidir a seção 9 antes da Fase 0. |
| Alto | Servidor HostGator com MySQL 5.7 (CentOS 7) | O compartilhado tem servidores CentOS 7.9 com MySQL 5.7 e AlmaLinux com MySQL 8.0; a versão depende do servidor da conta. Exigir AlmaLinux/MySQL 8 por escrito antes do primeiro deploy; se não houver, rever a hospedagem (seções 6.2 e 9). |
| Alto | Sem worker de fila e cron mínimo de 15 min | Termos da HostGator proíbem daemons e cron < 15 min. Fila database drenada pelo scheduler a cada 15 min, e-mails críticos na hora, chat por polling (seção 6.5). |
| Médio | Nunca rodou em MySQL | `date('now')` (só SQLite), subquery sem alias no CRM e DDL em transação no Migrator: prováveis erros no MySQL (não verificado; não havia MySQL). CI na mesma versão do servidor HostGator. |
| Médio | Limites de CPU, processos e conexões da HostGator | 25% dos recursos por ≥ 90 s, 25 processos e 25 conexões MySQL; excesso pode suspender a conta. Jobs curtos em lotes, sem IMAP na mesma conta, monitoramento; gatilhos de saída na seção 11. |
| Médio | Backups da HostGator não servem como estratégia | Sobrescritos em 24 h / 7 d / 30 d, restauração com taxa e no máximo 1 backup na conta. Dumps próprios cifrados e externos + ensaio de restauração (NI2-108, seção 6.8). |
| Médio | Limite de e-mail da HostGator não divulgado | Limite dinâmico por reputação (25/h nas primeiras 24 h). Provedor transacional externo com domínio autenticado (seção 6.9). |
| Médio | Localização dos dados (LGPD) não confirmada | A HostGator Latam migrou só parte da infraestrutura para a OCI em São Paulo. Confirmar por escrito e refletir na política de privacidade. |
| Médio | Testes que não testam | assert() é removido com zend.assertions=-1 (padrão de produção); 2 de 8 suítes falham com asserts ligados. |
| Médio | Repositório só no Mac, sem remote | Sem backup nem PR/review; criar o repo Laravel já com remote e CI. |
| Médio | Escopo grande no app do paciente e benchmark | NI2-11 (17 histórias) e NI2-112 (18) somam G+ cada; fatiar e manter fora do caminho crítico do MVP. |
| Baixo | Laravel 13 fora da lista de compatibilidade da HostGator | A lista oficial cita Laravel 10 e 11 e não há suporte a aplicações; validar no spike (PHP 8.3 + extensões, seção 6.13). |

---

## 2. Método e evidências

- **Jira:** todas as issues do projeto NI2 lidas via conector Atlassian (resumo, status, pai e descrição/critérios). As duplicadas NI2-15 a NI2-34 foram mapeadas uma a uma para NI2-35 a NI2-54 (mesmos títulos).
- **Código:** leitura integral de `src/`, `views/`, `public/`, `database/`, `bin/`, `tests/` e `mobile/src` (53 arquivos PHP, `php -l` sem erros em PHP 8.4). Mapa de commits: `d9f65c6` fundação/IAM · `1e5e913` DS/pacientes/prontuário/antropometria/alimentos/plano · `39fd0bb` agenda/app/engajamento/financeiro · `96e96e4` segurança/LGPD · `2be3fe4` rotas web · `6a5ca9d` ícones · `2563084`, `0d92642`, `5de8e25` e `075fbb4` ondas 1–4 do benchmark · `0a136f6` telas de login.
- **Execução:** cópia de rascunho com SQLite em memória. Rodei as 8 suítes (6 passam e 2 falham com `zend.assertions=1`) e duas PoCs (`poc_modulos.php` e `poc_ni2_6.php`). Não havia MySQL, então os comportamentos específicos de MySQL ficaram **não verificados**.
- **Hospedagem (v1.1):** restrições da HostGator Brasil pesquisadas em 07/10/2026 só em fontes públicas: central de ajuda, termos de serviço e blog oficiais da HostGator BR, documentação do Laravel 13 e manual do MySQL 8.0. Nada foi testado numa conta HostGator; o que não está documentado ficou como 'A confirmar no painel'. Links na seção 13.
- **Critério de estado:** *Implementado* = atende o aceite e é utilizável; *Parcial* = existe código que cobre parte do aceite (normalmente só serviço, sem rota/UI/testes válidos); *Só schema* = só tabela; *Inexistente* = nada, ou só simulação/mock.

---

## 3. Inventário por épico e história

Legenda de esforço (estimativa grosseira, 1 dev, com testes): **P** = até ~1 semana-dev; **M** = ~1–3 semanas-dev; **G** = ~3–6 semanas-dev (ou mais; fatiar).

### 3.1 Visão por épico

| Épico | Título | Jira | Histórias | Impl. | Parcial | Só schema | Inexist. | Esforço Laravel | Fase |
|---|---|---|---|---|---|---|---|---|---|
| NI2-1 | [P0] Fundação, autonomia e segregação do ClyoNutri | Feito | 5 | 1 | 3 | 0 | 1 | M | Fase 0 — Fundação |
| NI2-2 | [P0] Identidade, autenticação, RBAC e multi-tenancy | Feito | 5 | 0 | 3 | 1 | 1 | G | Fase 1 — Identidade, tenancy e auditoria |
| NI2-3 | [P0] Design System Clinical Botanical e Application Shell | Feito | 5 | 0 | 5 | 0 | 0 | M | Fase 1 — Design System em Blade |
| NI2-4 | [P0] Onboarding e perfil profissional do nutricionista | Feito | 5 | 0 | 0 | 2 | 3 | M | Fase 2 — Onboarding |
| NI2-5 | [P0] Gestão de pacientes e Perfil 360 | Feito | 5 | 0 | 4 | 1 | 0 | M | Fase 2 — Pacientes e Perfil 360 |
| NI2-6 | [P0] Prontuário e anamnese nutricional | Feito | 5 | 0 | 2 | 1 | 2 | G | Fase 3 — Prontuário e anamnese |
| NI2-7 | [P0] Antropometria, avaliação e evolução clínica | Feito | 5 | 0 | 3 | 0 | 2 | M | Fase 3 — Antropometria |
| NI2-8 | [P0] Base de alimentos, nutrientes e porções | Feito | 5 | 0 | 5 | 0 | 0 | M | Fase 4 — Base de alimentos |
| NI2-9 | [P0] Plano alimentar e prescrição nutricional | Feito | 6 | 0 | 5 | 0 | 1 | G | Fase 4 — Plano alimentar |
| NI2-10 | [P1] Agenda e jornada de consulta | Feito | 5 | 0 | 4 | 0 | 1 | M | Fase 5 — Agenda e consulta |
| NI2-11 | [P0] Portal e App do Paciente — Jornada Nutricional 360 | A fazer | 17 | 0 | 5 | 0 | 12 | G | Fase 5 — API e App do Paciente |
| NI2-12 | [P1] Engajamento, hidratação, check-ins e notificações | Feito | 5 | 0 | 3 | 1 | 1 | M | Fase 5 — Engajamento |
| NI2-13 | [P1] Financeiro essencial, recibos e relatórios | Feito | 6 | 0 | 3 | 0 | 3 | M | Fase 5 — Financeiro essencial |
| NI2-14 | [P0] Segurança, LGPD, qualidade, observabilidade e Go-Live | Feito | 9 | 0 | 6 | 0 | 3 | M | Fase 6 — Segurança, LGPD e Go-Live |
| NI2-112 | [Benchmark 2026] Paridade competitiva e diferenciação premium | Feito | 18 | 0 | 13 | 1 | 4 | G | Fase 7 — Benchmark (pós-MVP) |

### NI2-1 — [P0] Fundação, autonomia e segregação do ClyoNutri (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-35 | [Fundação] Inventariar baseline e classificar legado | Feito | **Parcial** | database/baseline/INVENTARIO_LEGADO.md (tabela KEEP/ADAPT/MIGRATE/DROP-LATER, 17 itens) | REAPROVEITAR | P |
| NI2-36 | [Fundação] Criar aplicação ClyoNutri independente | Feito | **Parcial** | public/index.php:1-116 (front controller), composer.json (PSR-4), config/ vazio; index.php:24-39 faz seed de TACO e usuário demo a cada requisição | REESCREVER | P |
| NI2-37 | [Fundação] Criar banco e migrations próprios | Feito | **Parcial** | database/migrations/2026_10_07_000001..000007 (sem down()/rollback); database/seeds/ vazio; sem índices compostos; Migrator.php envolve DDL em transação (provável falha em MySQL, não verificado) | MIGRAR | M |
| NI2-38 | [Fundação] Configurar ambientes, build, deploy e rollback | Feito | **Inexistente** | .env.example apenas (APP_KEY placeholder fixo); sem CI, sem remote git, sem checklist de deploy, sem staging | NOVO | M |
| NI2-39 | [Fundação] Remover domínios Med/Psi/Fit do produto | Feito | **Implementado** | rg por psi/psico/treino/workout/medic/clyomed em src, views, public, mobile/src e migrations: 0 ocorrências | REAPROVEITAR | P |

### NI2-2 — [P0] Identidade, autenticação, RBAC e multi-tenancy (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-40 | [IAM] Cadastro e verificação de conta profissional | Feito | **Parcial** | AuthService.php:22-101 (bcrypt 12, transação clínica+usuário+membro+profissional, audit); :36 mensagem revela e-mail existente (enumeração); :55 is_verified=1 sem verificação; rota /cadastro inexistente (login.php:117 aponta para 404) | REESCREVER | M |
| NI2-41 | [IAM] Login, logout e sessão segura | Feito | **Parcial** | AuthController.php:36-67 (session_regenerate_id :58); index.php:43-49 (HttpOnly/SameSite); logout via GET index.php:68 (sem CSRF); sem rate limit; demo credentials em login.php:112-113 | REESCREVER | P |
| NI2-42 | [IAM] Recuperação e redefinição de senha | Feito | **Só schema** | migration 000001:81-87 (password_resets); login.php:100 link 'Esqueceu?' href='#' | NOVO | P |
| NI2-43 | [IAM] RBAC profissional, equipe e paciente | Feito | **Inexistente** | clinic_members.role gravado (migration 000001:41-51, AuthService.php:65) mas nunca consultado; index.php:82-90 só verifica login | NOVO | M |
| NI2-44 | [IAM] Isolamento multi-tenant e proteção IDOR | Feito | **Parcial** | Leituras filtram clinic_id (ex.: PatientRepository.php:56-75); escritas não validam posse: PoC poc_modulos.php — 12 serviços aceitaram patient_id/recurso de outro tenant; TenantContext.php estático | REESCREVER | G |

### NI2-3 — [P0] Design System Clinical Botanical e Application Shell (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-45 | [Design] Definir tokens Clinical Botanical | Feito | **Parcial** | public/assets/css/clinical-botanical.css:6-60 — tokens Navy #0F2747 / Copper #E67A3F / Inter (Premium), divergem do aceite (Forest/Green/Sage/Cream) | REAPROVEITAR | P |
| NI2-46 | [Design] Construir biblioteca de componentes base | Feito | **Parcial** | CSS só tem .btn*, .badge*, .card, .form-control/.form-label/.form-group, .table (clinical-botanical.css); faltam modal/drawer/tabs/toast/skeleton/pagination/checkbox/radio | REAPROVEITAR | M |
| NI2-47 | [Design] Criar Application Shell profissional | Feito | **Parcial** | views/layouts/professional-shell.php:20-48 (nav sem Biblioteca/Relatórios; /financeiro e /configuracoes sem rota); logout por link GET :66; sem @media no CSS (não responsivo), sem breadcrumb/menu mobile, sem RBAC | REAPROVEITAR | P |
| NI2-48 | [Design] Criar shell mobile-first do paciente | Feito | **Parcial** | mobile/src/screens/PatientHomeScreen.tsx (mock estático: :5 water 1250, :16 'Olá, Roberto'); views/patient/ vazia | REAPROVEITAR | M |
| NI2-49 | [Design] Governança visual, responsividade e acessibilidade | Feito | **Parcial** | CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md (especificação); sem checklist de revisão, sem testes de contraste/foco, CSS sem media queries | NOVO | P |

### NI2-4 — [P0] Onboarding e perfil profissional do nutricionista (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-50 | [Onboarding] Criar onboarding guiado do nutricionista | Feito | **Inexistente** | Nenhum código de onboarding (rg 'onboarding' só em docs) | NOVO | M |
| NI2-53 | [Onboarding] Medir ativação e primeiro valor | Feito | **Inexistente** | Nenhum evento de ativação/analytics | NOVO | P |
| NI2-54 | [Onboarding] Editar perfil e configurações após ativação | Feito | **Inexistente** | /configuracoes sem rota (shell :45 → 404); nenhum update de perfil | NOVO | P |
| NI2-51 | [Onboarding] Cadastrar perfil profissional | Feito | **Só schema** | migration 000001:54-65 (professionals: crn, crn_region, specialty, bio, signature_path); só crn/crn_region gravados no registro (AuthService.php:72-77) | MIGRAR | P |
| NI2-52 | [Onboarding] Configurar clínica/equipe e preferências | Feito | **Só schema** | migration 000001:14-51 (clinics, clinic_members); sem timezone/locale/preferências; sem convite de equipe | MIGRAR | M |

### NI2-5 — [P0] Gestão de pacientes e Perfil 360 (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-55 | [Pacientes] Cadastrar novo paciente | Feito | **Parcial** | PatientRepository.php:20-54 (sem validação, sem deduplicação, sem audit); botão 'Cadastrar Paciente' sem ação (DashboardController.php:147); sem rota POST | MIGRAR | P |
| NI2-56 | [Pacientes] Listar, buscar e filtrar pacientes | Feito | **Parcial** | DashboardController.php:130-189 + PatientRepository.php:67-75 (lista tudo, sem busca/filtro/paginação) | MIGRAR | P |
| NI2-57 | [Pacientes] Construir Perfil 360 | Feito | **Parcial** | PatientRepository.php:77-112 (anamneses, antropometria, planos); rota /pacientes/{id} inexistente (link DashboardController.php:176 → 404); sem consultas/check-ins/financeiro/timeline | MIGRAR | M |
| NI2-58 | [Pacientes] Gerenciar cadastro e status | Feito | **Só schema** | patients.status (migration 000002:23); nenhum método de update/inativação | NOVO | P |
| NI2-59 | [Pacientes] Consentimentos e privacidade | Feito | **Parcial** | patient_consents (migration 000002:31-41) + insert sem versão/origem (PatientRepository.php:44-51; ip_address nunca preenchido); CompetitorMigrationService.php:89 cria consentimento automaticamente | MIGRAR | M |

### NI2-6 — [P0] Prontuário e anamnese nutricional (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-60 | [Prontuário] Registro nutricional longitudinal | Feito | **Só schema** | migration 000002:44-56 (nutrition_records) sem nenhum código | NOVO | M |
| NI2-61 | [Anamnese] Criar anamnese nutricional estruturada | Feito | **Parcial** | AnamnesisService.php:20-64; migration 000002:59-75 (faltam objetivos/restrições/sintomas); sem validação/UI | MIGRAR | M |
| NI2-62 | [Anamnese] Versionar e preservar histórico | Feito | **Parcial** | AnamnesisService.php:25-30 (MAX+1 sem lock/UNIQUE); migration 000002:73 ON DELETE CASCADE; UPDATE permitido | MIGRAR | M |
| NI2-63 | [Prontuário] Observações clínicas seguras | Feito | **Inexistente** | Sem tabela/serviço/view | NOVO | M |
| NI2-64 | [Prontuário] Resumo clínico para prescrição | Feito | **Inexistente** | Sem resumo; ConsultationSummaryAssistant.php:54-57 inventa texto clínico (violaria o aceite) | NOVO | M |

### NI2-7 — [P0] Antropometria, avaliação e evolução clínica (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-65 | [Antropometria] Registrar avaliação | Feito | **Parcial** | AnthropometryService.php:20-74 (sem validação de plausibilidade; aceita paciente de outro tenant — PoC); sem rota/view | MIGRAR | P |
| NI2-66 | [Antropometria] Calcular IMC, RCQ e derivados | Feito | **Parcial** | AnthropometryService.php:24-35 (IMC, RCQ); peso ausente vira 0 (valor falso) e altura 0 gera DivisionByZero; teste só via assert (golden_path_test.php:80-81) | REAPROVEITAR | P |
| NI2-67 | [Antropometria] Exibir evolução temporal | Feito | **Parcial** | AnthropometryService.php:76-87 (getEvolution devolve série ordenada); sem tabela/gráfico/view, sem filtro de período, sem alternativa textual | MIGRAR | M |
| NI2-68 | [Antropometria] Comparar avaliações | Feito | **Inexistente** | Nenhum código além da listagem em getProfile360 (PatientRepository.php:93-97) | NOVO | P |
| NI2-69 | [Antropometria] Compartilhar evolução selecionada | Feito | **Inexistente** | Nenhum código além da listagem em getProfile360 (PatientRepository.php:93-97) | NOVO | M |

### NI2-8 — [P0] Base de alimentos, nutrientes e porções (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-70 | [Alimentos] Modelar catálogo, nutrientes e porções | Feito | **Parcial** | migration 000003:14-30 (foods com macros por 'serving_size_g'); sem food_nutrients nem tabela de medidas caseiras; base de cálculo ambígua; sem índice em name | MIGRAR | M |
| NI2-71 | [Alimentos] Importar referência compatível com TACO | Feito | **Parcial** | FoodCatalogService.php:20-65 — 12 alimentos fixos no código; executado a cada request (index.php:24-25, DashboardController.php:194); sem fonte/versão/relatório | REESCREVER | M |
| NI2-72 | [Alimentos] Cadastrar alimentos personalizados por tenant | Feito | **Parcial** | FoodCatalogService.php:88-119 (create com clinic_id; sem update/delete/validação); alimento custom de outro tenant aceito em plano (PoC) | MIGRAR | P |
| NI2-73 | [Alimentos] Busca eficiente no catálogo | Feito | **Parcial** | FoodCatalogService.php:67-86 (LIKE %termo% LIMIT 50, sem índice/paginação); /alimentos lista tudo (DashboardController.php:191-245) | MIGRAR | P |
| NI2-74 | [Alimentos] Conversão de porções e cálculo nutricional | Feito | **Parcial** | MealPlanService.php:71-77 e RecipeService.php:34-45 calculam amount/serving_size_g × valor; measure_unit ignorada. PoC: 30 g de aveia = 394 kcal (esperado ~118) | REESCREVER | M |

### NI2-9 — [P0] Plano alimentar e prescrição nutricional (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-75 | [Plano] Criar plano alimentar em rascunho | Feito | **Parcial** | MealPlanService.php:20-115 (transação, status draft); sem validação de posse do paciente (PoC); paciente só vê published (PatientApiController.php:30-35) | MIGRAR | M |
| NI2-76 | [Plano] Organizar refeições e horários | Feito | **Parcial** | Só criação em lote (MealPlanService.php:41-52); sem editar/remover/reordenar; sem UI | MIGRAR | M |
| NI2-77 | [Plano] Adicionar alimentos, quantidades e totais nutricionais | Feito | **Parcial** | Só kcal diária total (MealPlanService.php:99-101), sem macros/total por refeição; cálculo com bug (ver NI2-74) | MIGRAR | M |
| NI2-78 | [Plano] Configurar substituições e orientações | Feito | **Parcial** | MealPlanService.php:79-94 + migration 000003:77-87; sem preview | MIGRAR | P |
| NI2-79 | [Plano] Revisar e publicar versão | Feito | **Parcial** | MealPlanService.php:117-126 publica sem arquivar o anterior nem versionar (PoC: 2 planos 'published' simultâneos, version=1 em ambos); sem audit/preview | MIGRAR | M |
| NI2-80 | [Plano] Duplicar e ajustar preservando histórico | Feito | **Inexistente** | meal_plans.version (migration 000003:39) nunca usado; não há duplicação | NOVO | P |

### NI2-10 — [P1] Agenda e jornada de consulta (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-81 | [Agenda] Criar e editar agendamento | Feito | **Parcial** | AppointmentService.php:20-48 (sem edição, sem detecção de conflito — PoC aceitou sobreposição; sem validação de posse) | MIGRAR | M |
| NI2-82 | [Agenda] Estados e ciclo da consulta | Feito | **Parcial** | AppointmentService.php:50-69 (lista de status sem no_show; qualquer transição permitida; sem histórico/timestamps por estado) | MIGRAR | P |
| NI2-83 | [Agenda] Agenda diária e semanal responsiva | Feito | **Parcial** | AppointmentService.php:71-84 (só por dia); /agenda redireciona para /dashboard (index.php:106-108) | MIGRAR | M |
| NI2-84 | [Consulta] Tela de execução do atendimento | Feito | **Inexistente** | Sem rota/view | NOVO | M |
| NI2-85 | [Dashboard] Agenda e pacientes que exigem atenção | Feito | **Parcial** | DashboardController.php:14-128 (consultas do dia e contagem de pacientes reais); métricas fixas no código: 'ADESÃO MÉDIA 88.5%' (:55) e 'PLANOS A REVISAR 2' (:63) | MIGRAR | P |

### NI2-11 — [P0] Portal e App do Paciente — Jornada Nutricional 360 (Jira: A fazer)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-86 | [Paciente] Convite e ativação segura | Feito | **Inexistente** | Sem convite/token/usuário paciente | NOVO | M |
| NI2-87 | [Paciente] Home orientada ao dia | Feito | **Inexistente** | Só mock estático em mobile/src/screens/PatientHomeScreen.tsx (dados fixos, sem API) | NOVO | M |
| NI2-88 | [Paciente] Visualizar plano publicado | Feito | **Parcial** | PatientApiController.php:26-75 (só published; N+1 em :49-69); não roteado; sem autenticação de paciente | MIGRAR | M |
| NI2-89 | [API] API versionada do paciente | Feito | **Inexistente** | Nenhuma rota /api; PatientApiController não registrado no router (index.php) | NOVO | M |
| NI2-90 | [Mobile] Base React Native + Expo + TypeScript | Feito | **Parcial** | mobile/package.json (Expo ~51, RN 0.74); 1 tela mock; mobile/src/services e components vazios; sem navegação/sessão/ambientes | MIGRAR | M |
| NI2-91 | [Paciente] Perfil, consentimentos e privacidade | Feito | **Inexistente** | LgpdService existe só para operador, sem fluxo do paciente | NOVO | M |
| NI2-131 | [Paciente][P0] Onboarding nutricional, preferências e vínculo profissional | A fazer | **Inexistente** | — | NOVO | M |
| NI2-132 | [Paciente][P0] Registro de refeições e adesão ao plano publicado | A fazer | **Parcial** | FoodDiaryService.php:23-57 registra refeição livre, sem vínculo a plano/versão nem idempotência | MIGRAR | M |
| NI2-133 | [Paciente][P0] Diário alimentar e linha do tempo de acompanhamento | A fazer | **Parcial** | FoodDiaryService.php:82-103 (timeline sem paginação/histórico de edição) | MIGRAR | M |
| NI2-134 | [Paciente][P0] Substituições alimentares autorizadas e receitas | A fazer | **Inexistente** | Substituições e receitas existem só no lado profissional (MealPlanService/RecipeService) | NOVO | M |
| NI2-135 | [Paciente][P0] Evolução, metas e autorrelatos com proveniência | A fazer | **Inexistente** | — | NOVO | M |
| NI2-136 | [Paciente][P1] Exames, documentos e orientações com acesso seguro | A fazer | **Inexistente** | Sem upload seguro no projeto | NOVO | M |
| NI2-137 | [Paciente][P1] Próxima consulta e ações permitidas de agenda | A fazer | **Inexistente** | — | NOVO | P |
| NI2-138 | [Paciente][P1] Canal seguro de comunicação com nutricionista | A fazer | **Parcial** | ChatService.php:20-120 (sem autorização de participante; PoC: mensagem gravada em conversa de outro tenant) | MIGRAR | M |
| NI2-139 | [Paciente][P1] Offline, sincronização e consistência entre app e portal | A fazer | **Inexistente** | — | NOVO | G |
| NI2-140 | [Paciente][P0] Acessibilidade e consistência visual portal/iOS/Android | A fazer | **Inexistente** | — | NOVO | M |
| NI2-141 | [Paciente][P0] Homologação E2E, privacidade e gates de lançamento | A fazer | **Inexistente** | — | NOVO | M |

### NI2-12 — [P1] Engajamento, hidratação, check-ins e notificações (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-92 | [Engajamento] Registrar hidratação | Feito | **Parcial** | EngagementService.php:20-38 (sem meta/edição/timezone); :73 usa date('now') — só SQLite, no MySQL retorna NULL (provável, não verificado) | MIGRAR | P |
| NI2-93 | [Engajamento] Check-in estruturado | Feito | **Parcial** | EngagementService.php:40-64 (campos fixos, sem perguntas versionadas/recorrência) | MIGRAR | M |
| NI2-94 | [Engajamento] Visão profissional de adesão e atenção | Feito | **Parcial** | EngagementService.php:66-91 (resumo por paciente); dashboard mostra 88.5% fixo (DashboardController.php:55) | MIGRAR | M |
| NI2-95 | [Notificações] Eventos, preferências e entregas | Feito | **Só schema** | migration 000004:90-101 (notifications) sem código | NOVO | M |
| NI2-96 | [Analytics] Métricas de adesão com privacidade | Feito | **Inexistente** | — | NOVO | P |

### NI2-13 — [P1] Financeiro essencial, recibos e relatórios (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-97 | [Financeiro] Criar recebível por atendimento | Feito | **Parcial** | FinanceService.php:20-47 (centavos inteiros OK; sem validação; paciente/consulta de outro tenant aceitos — PoC) | MIGRAR | P |
| NI2-98 | [Financeiro] Pagamento parcial e total | Feito | **Parcial** | FinanceService.php:49-100 (transação; parcial/total); PoC: valor negativo e método 'bitcoin' aceitos; sem idempotência/audit/lock | MIGRAR | M |
| NI2-99 | [Financeiro] Estorno e cancelamento rastreáveis | Feito | **Inexistente** | Status 'cancelled' só em comentário (migration 000004:67) | NOVO | M |
| NI2-100 | [Financeiro] Gerar recibo | Feito | **Parcial** | FinanceService.php:102-118 (dados do recibo; inclui e-mail do paciente; receipt_code sem UNIQUE); sem impressão/download | MIGRAR | P |
| NI2-101 | [Financeiro] Relatórios e exportação CSV | Feito | **Inexistente** | — | NOVO | P |
| NI2-102 | [Financeiro] Dashboard financeiro essencial | Feito | **Inexistente** | /financeiro sem rota (shell :41 → 404) | NOVO | P |

### NI2-14 — [P0] Segurança, LGPD, qualidade, observabilidade e Go-Live (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-103 | [Segurança] Trilha de auditoria | Feito | **Parcial** | AuditLogger.php:20-44 + audit_logs (migration 000001:90-101); usado só em LgpdService, CompetitorMigration e registro; sem campo resultado, sem acesso restrito/retenção; PII em details (LgpdService.php:54, AuthService.php:85) | REESCREVER | M |
| NI2-104 | [LGPD] Exportação, exclusão e anonimização | Feito | **Parcial** | LgpdService.php:26-103 (export e anonimização parciais; não cobre anamneses/texto livre; sem solicitação rastreável/rota) | MIGRAR | M |
| NI2-105 | [Segurança] Testes IDOR, cross-tenant, CSRF, XSS e SQLi | Feito | **Parcial** | tests/security_lgpd_test.php (assert() desativado por padrão; teste de SQLi não usa o input malicioso :79-81); só PatientRepository coberto | REESCREVER | M |
| NI2-106 | [Segurança] Hardening de arquivos, uploads, secrets e webroot | Feito | **Parcial** | public/ como webroot e storage fora dele (OK); photo_path/attachment_path são strings livres sem upload validado (FoodDiaryService.php:42, ChatService.php:68); APP_KEY fixa em .env.example | REESCREVER | M |
| NI2-107 | [Operação] Observabilidade e health checks | Feito | **Parcial** | /health estático sem checar DB (index.php:71-79, versão '1.0.0' fixa); bin/healthcheck.php:16-75 (CLI); sem logs estruturados/correlação | REESCREVER | P |
| NI2-108 | [Operação] Backup, restore e migration rehearsal | Feito | **Inexistente** | storage/backups/.gitkeep apenas | NOVO | M |
| NI2-109 | [QA] E2E Golden Path profissional e paciente | Feito | **Parcial** | tests/golden_path_test.php (nível de serviço, sem HTTP/UI, sem jornada do paciente; assert-based) | REESCREVER | M |
| NI2-110 | [QA] Performance, acessibilidade e responsividade | Feito | **Inexistente** | — | NOVO | M |
| NI2-111 | [Go-Live] UAT, release gate, deploy, smoke test e rollback | Feito | **Inexistente** | — | NOVO | M |

### NI2-112 — [Benchmark 2026] Paridade competitiva e diferenciação premium (Jira: Feito)

| História | Título | Jira | Estado real | Evidência (arquivo:linha) | Classificação | Esforço |
|---|---|---|---|---|---|---|
| NI2-113 | [P0][Benchmark] Matriz de gaps e validação comparativa com nutricionistas | Feito | **Parcial** | docs/benchmark/MATRIZ_GAPS_BENCHMARK_2026.md (4 módulos; marcado 'Validado e Aprovado' sem entrevistas/evidências) | REAPROVEITAR | M |
| NI2-114 | [P0][Benchmark] Prescrição avançada com equivalências, receitas e templates | Feito | **Parcial** | TemplateService.php:20-86; RecipeService.php:20-113 (mesmo bug de cálculo); food_equivalence_* só schema (migration 000005:57-73); sem UI | MIGRAR | G |
| NI2-115 | [P0][Benchmark] Diário alimentar com fotos, sintomas e contextualização | Feito | **Parcial** | FoodDiaryService.php:23-103; foto é só string (sem upload); sem ocultar/apagar; aceita paciente de outro tenant (PoC) | MIGRAR | M |
| NI2-116 | [P0][Benchmark] Plano alimentar com lista de compras e receitas | Feito | **Parcial** | ShoppingListService.php:47-115 (agregação e corredores OK; não filtra plano publicado — PoC gerou lista de draft; sem receitas/alergênicos) | REAPROVEITAR | P |
| NI2-117 | [P1][Benchmark] Check-in longitudinal e acompanhamento colaborativo | Feito | **Parcial** | EngagementService.php:96-120 — ORDER BY ASC + LIMIT devolve os check-ins mais antigos, não as últimas semanas (bug) | MIGRAR | M |
| NI2-118 | [P1][Benchmark] Chat seguro profissional-paciente e central de comunicação | Feito | **Parcial** | ChatService.php:20-120 + migration 000006:33-58 (sem autorização de participante, anexos sem validação; PoC cross-tenant) | MIGRAR | M |
| NI2-119 | [P1][Benchmark] Assistente de IA para rascunho de plano com aprovação profissional | Feito | **Inexistente** | MealPlanDraftAssistant.php:30-94 é simulação: alimentos fixos (:53-56), ignora targetKcal, rotula 'Sugerido por IA' (:60,:68); sem IA, sem log de aprovação | DESCARTAR | G |
| NI2-120 | [P1][Benchmark] Migração assistida de concorrentes por CSV e documentos | Feito | **Parcial** | CompetitorMigrationService.php:29-112 (mapeia cabeçalhos; sem dedupe — PoC importou duplicata; cria consentimento LGPD automaticamente :89; explode por linha quebra CSV multilinha :33; síncrono) | MIGRAR | M |
| NI2-121 | [P1][Benchmark] Agendamento público e página profissional premium | Feito | **Parcial** | PublicProfileService.php:20-80 — UPDATE por professional_id sem tenant (PoC: outro tenant sobrescreveu o perfil); is_verified DEFAULT 1 (migration 000007:37); sem slots/agendamento | MIGRAR | G |
| NI2-122 | [P1][Benchmark] Cobrança integrada, pacotes e recorrência | Feito | **Parcial** | PracticeCrmService.php:20-63 (pacotes); sem PSP/webhook/recorrência | MIGRAR | G |
| NI2-123 | [P1][Benchmark] Clínicas: multiusuário, multiagenda e permissão granular | Feito | **Só schema** | clinic_members (migration 000001:41-51) sem unidades/salas/permissões | NOVO | G |
| NI2-124 | [P1][Benchmark] Integração com calendário e teleconsulta | Feito | **Inexistente** | TelehealthService.php:20-44 gera URL fictícia https://meet.clyonutri.com.br/room/... (:24) sem provedor real | DESCARTAR | G |
| NI2-125 | [P1][Benchmark] Health Connect e Apple Health com autorização explícita | Feito | **Parcial** | HealthSyncService.php:20-60 + migration 000007:69-81 (sem consentimento/revogação; sem integração no app) | MIGRAR | G |
| NI2-126 | [P1][Benchmark] Dashboard de sucesso do consultório e CRM leve | Feito | **Parcial** | PracticeCrmService.php:65-105 (taxa de retorno conta qualquer consulta, inclusive canceladas; subquery sem alias em :85-91 — provável erro no MySQL, não verificado) | MIGRAR | M |
| NI2-127 | [P1][Benchmark] Personalização da experiência e auditoria visual premium | Feito | **Parcial** | Tokens Premium no CSS + ícones Lucide (commit 6a5ca9d); arquivo ainda chamado clinical-botanical.css; sem testes de a11y/visuais | REAPROVEITAR | M |
| NI2-128 | [P2][Benchmark] Diretório de nutricionistas e captação verificável | Feito | **Inexistente** | — | NOVO | G |
| NI2-129 | [P2][Benchmark] Academy, templates e programa de indicações | Feito | **Parcial** | ReferralService.php:19-48 (código e recompensa sem antifraude/audit; aceita qualquer código); Academy inexistente | MIGRAR | M |
| NI2-130 | [P1][Benchmark] Assistente para documentação e resumo de atendimento | Feito | **Inexistente** | ConsultationSummaryAssistant.php:24-75 — heurística por palavra-chave que preenche seções vazias com texto clínico inventado (:54-57), violando o critério 3; nada é salvo | DESCARTAR | M |

### Duplicadas (NI2-15 a NI2-34)

Todas estão "Feito" e duplicam, pelo título, NI2-35 a NI2-54 (15→35, 16→36 … 34→54). O estado real é o mesmo da original correspondente. Recomendação: copiar critérios exclusivos (se houver) e fechar como duplicadas.

---

## 4. Inventário de módulos e artefatos de código

| Artefato | Tipo | Classificação | Destino no Laravel | Motivo |
|---|---|---|---|---|
| `public/index.php (bootstrap + rotas)` | Core | **REESCREVER** | routes/web.php, bootstrap/app.php | Faz seed de TACO e cria usuário demo a cada requisição (:24-39); rotas e middleware viram idioma Laravel. |
| `src/Core/Http/Router.php` | Core | **REESCREVER** | Roteamento Laravel (routes/web.php, routes/api.php) | Router próprio sem suporte a PUT/DELETE, sem middleware; nativo no framework. |
| `src/Core/Security/Csrf.php` | Core | **REESCREVER** | Middleware CSRF nativo + @csrf no Blade | Nativo no Laravel. |
| `src/Core/Security/Env.php` | Core | **DESCARTAR** | config/*.php + .env do Laravel | Substituído pelo carregamento de env/config do framework. |
| `src/Core/Database/Connection.php` | Core | **DESCARTAR** | config/database.php | Nativo; default SQLite atual não serve para produção. |
| `src/Core/Database/Migrator.php + bin/migrate.php` | Core | **DESCARTAR** | php artisan migrate | Migrator envolve DDL em transação (provável erro no MySQL). |
| `src/Core/Auth/AuthService.php` | Core | **REESCREVER** | Fortify + Action CreateNewUser | Login/registro viram Fortify; a transação de criação de tenant+membro+profissional (:43-100) é reaproveitada como lógica da Action. |
| `src/Core/Auth/TenantContext.php` | Core | **REESCREVER** | Support/Tenancy: TenantManager + middleware + TenantScope + trait BelongsToTenant | Contexto estático não garante isolamento em escrita (PoC: 12 serviços vulneráveis). |
| `src/Core/Audit/AuditLogger.php` | Core | **REESCREVER** | Support/Audit: AuditLogger + trait Auditable + observers | Hoje quase não é chamado e grava PII em details. |
| `src/Core/Validation/ (vazio)` | Core | **REESCREVER** | FormRequests por caso de uso | Não existe camada de validação. |
| `src/Application/Controllers/AuthController.php` | Controller | **REESCREVER** | Fortify (views Blade próprias) | Fluxo de auth nativo; manter só o markup. |
| `src/Application/Controllers/DashboardController.php` | Controller | **MIGRAR** | Web\DashboardController, PatientController, FoodController + views Blade | HTML inline no controller vira Blade; métricas fixas (:55, :63) são DESCARTADAS. |
| `src/Application/Api/PatientApiController.php` | Controller | **REESCREVER** | Api\V1\* controllers + API Resources + Sanctum | Não roteado, sem auth, N+1 (:49-69). |
| `views/layouts/professional-shell.php` | View | **REAPROVEITAR** | resources/views/layouts/professional.blade.php + components | Markup e ícones Lucide prontos; falta responsividade/RBAC. |
| `views/auth/login.php` | View | **REAPROVEITAR** | resources/views/auth/login.blade.php | Remover credenciais demo (:111-113) e link morto 'Esqueceu?'. |
| `public/assets/css/clinical-botanical.css` | View/CSS | **REAPROVEITAR** | resources/css/design-system.css (Vite) | Tokens Premium corretos; renomear; adicionar media queries e componentes faltantes. |
| `src/Domain/Patient/PatientRepository.php` | Domínio | **MIGRAR** | Models\Patient (+TenantScope) + PatientService | Queries simples viram Eloquent; profile360 com eager loading. |
| `src/Domain/Anamnesis/AnamnesisService.php` | Domínio | **MIGRAR** | Models\Anamnesis (append-only) + CreateAnamnesisVersion | Ver code-review-NI2-6.md (posse, versão única, audit). |
| `src/Domain/Anthropometry/AnthropometryService.php` | Domínio | **REAPROVEITAR** | Domain\Anthropometry\AnthropometryCalculator (puro) + model | Fórmulas IMC/RCQ (:24-35) portáveis; corrigir ausência→null. |
| `src/Domain/Food/FoodCatalogService.php` | Domínio | **MIGRAR** | Models\Food (escopo globais+tenant) + comando ImportTaco | Busca/custom migram; seed fixo de 12 itens vira fixture de teste. |
| `src/Domain/MealPlan/MealPlanService.php` | Domínio | **MIGRAR** | MealPlan models + Actions (CreateDraft, Publish, Duplicate) | Estrutura transacional boa; cálculo e publicação precisam de correção. |
| `src/Domain/MealPlan/TemplateService.php` | Domínio | **MIGRAR** | Models\MealPlanTemplate + ApplyTemplate | Validar food_id do payload contra o tenant. |
| `src/Domain/Recipe/RecipeService.php` | Domínio | **MIGRAR** | Models\Recipe + NutritionCalculator | Cálculo de macros por porção deve usar o calculador corrigido. |
| `src/Domain/ShoppingList/ShoppingListService.php` | Domínio | **REAPROVEITAR** | Domain\ShoppingList\ShoppingListBuilder (puro) | Agregação e mapAisle (:23-42) são lógica pura; filtrar só plano publicado. |
| `src/Domain/Appointment/AppointmentService.php` | Domínio | **MIGRAR** | Models\Appointment + enum AppointmentStatus + transições | Faltam conflito, no_show, histórico. |
| `src/Domain/Engagement/EngagementService.php` | Domínio | **MIGRAR** | Models HydrationLog/Checkin + services | date('now') só SQLite (:73); bug de ordenação nas tendências (:104-105). |
| `src/Domain/Finance/FinanceService.php` | Domínio | **MIGRAR** | Models Receivable/Payment + Actions | Regra de saldo parcial/total reaproveitável; faltam validação, ledger, estorno. |
| `src/Domain/FoodDiary/FoodDiaryService.php` | Domínio | **MIGRAR** | Models\FoodDiaryEntry + upload privado | Foto como string livre. |
| `src/Domain/Chat/ChatService.php` | Domínio | **MIGRAR** | Models Conversation/Message + ConversationPolicy; fase 1 por polling (sem websockets na HostGator) | Sem autorização de participante. |
| `src/Domain/Privacy/LgpdService.php` | Domínio | **MIGRAR** | Domain\Privacy (jobs em lotes ExportSubjectData/AnonymizePatient) | Cobertura incompleta (anamneses, texto livre). |
| `src/Domain/Intelligence/ConsultationSummaryAssistant.php` | Domínio | **DESCARTAR** | — | Inventa texto clínico (:54-57); viola NI2-64/NI2-130. |
| `src/Domain/Intelligence/MealPlanDraftAssistant.php` | Domínio | **DESCARTAR** | — | Simulação rotulada como IA com alimentos fixos (:53-60). |
| `src/Domain/Migration/CompetitorMigrationService.php` | Domínio | **MIGRAR** | Jobs\ImportPatientsCsv em lotes (fila database via scheduler) + prévia/relatório | Mapeamento de cabeçalho e datas reaproveitável; consentimento automático (:89) DESCARTADO. |
| `src/Domain/Telehealth/TelehealthService.php` | Domínio | **DESCARTAR** | — | Gera URL fictícia (:24) sem provedor. |
| `src/Domain/PublicProfile/PublicProfileService.php` | Domínio | **MIGRAR** | Models\PublicProfile + Policy | UPDATE sem tenant (PoC); is_verified deve começar falso. |
| `src/Domain/Crm/PracticeCrmService.php` | Domínio | **MIGRAR** | Packages + métricas via queries Eloquent | Subquery sem alias (MySQL); métrica de retorno imprecisa. |
| `src/Domain/HealthSync/HealthSyncService.php` | Domínio | **MIGRAR** | Models\HealthMetric (após modelo de consentimento) | Sem consentimento/revogação; adiar (P1 benchmark). |
| `src/Domain/Academy/ReferralService.php` | Domínio | **MIGRAR** | Models\Referral (adiar, P2) | Sem antifraude/audit. |
| `database/migrations/000001–000007` | Dados | **MIGRAR** | database/migrations Laravel (tenant_id, índices, UNIQUE, RESTRICT, down()) | Modelo de dados é a principal herança; corrigir conforme decisões da seção 9. |
| `Tabelas sem código: nutrition_records, food_equivalence_*, notifications, sessions, password_resets` | Dados | **MIGRAR** | Tabelas nativas Laravel (sessions, password_reset_tokens, notifications) + modelo clínico novo | Schema existe sem uso; recriar conforme decisões. |
| `Usuário demo + credenciais na tela (index.php:27-39, login.php:112-113)` | Dados | **DESCARTAR** | DemoSeeder só em ambiente local | Credencial fixa não pode ir para produção. |
| `Métricas fixas do dashboard (DashboardController.php:55, :63)` | View | **DESCARTAR** | Métricas calculadas | Números inventados. |
| `tests/*.php (8 suítes assert-based)` | Testes | **REESCREVER** | tests/Feature + tests/Unit com Pest + RefreshDatabase (MySQL 8 no CI; versão igual à do servidor HostGator) | assert() desativado por padrão; 2 suítes falham com asserts ligados; cenários servem de roteiro. |
| `bin/healthcheck.php` | Operação | **REESCREVER** | Rota /up + listener DiagnosingHealth (DB, heartbeat do scheduler, idade da fila) | — |
| `.env.example` | Operação | **REESCREVER** | .env.example do Laravel | APP_KEY fixa; DB default SQLite. |
| `mobile/ (Expo ~51, 1 tela mock)` | Mobile | **REAPROVEITAR** | App RN separado consumindo /api/v1 (Sanctum) | Só referência visual; fora do Laravel; atualizar SDK do Expo. |
| `database/baseline/INVENTARIO_LEGADO.md` | Docs | **REAPROVEITAR** | docs/ | Insumo para o modelo de dados. |
| `docs/benchmark/MATRIZ_GAPS_BENCHMARK_2026.md` | Docs | **REAPROVEITAR** | docs/ | Rascunho não validado. |
| `CLYONUTRI_DESIGN_SYSTEM_PREMIUM.md` | Docs | **REAPROVEITAR** | docs/design-system.md | Especificação oficial dos tokens/componentes. |

---

## 5. Mapeamento do Core próprio para Laravel 13

| Peça atual | Equivalente Laravel 13 | Pacote |
|---|---|---|
| Router próprio (`Core/Http/Router.php`) | `routes/web.php` (Blade) e `routes/api.php` com prefixo `v1`; route model binding com `scopeBindings()` | Nativo |
| Front controller + bootstrap (`public/index.php`) | `public/index.php` + `bootstrap/app.php` (middleware, exceções). Sem seeds em runtime | Nativo |
| `Env` + `Connection` + `Migrator` | `.env`, `config/database.php` (mysql), `php artisan migrate` com `down()` | Nativo |
| `AuthService` + `AuthController` (sessão) | **Fortify** (headless: registro, login, verificação de e-mail, reset de senha, 2FA opcional) com views Blade próprias do DS. Rate limit pelo RateLimiter | `laravel/fortify` |
| Sem auth de API | **Sanctum** com tokens por dispositivo para o app RN (abilities, revogação no logout), instalado via `php artisan install:api` | `laravel/sanctum` |
| `Csrf` | Middleware CSRF nativo + `@csrf`; API autenticada por token fica fora do CSRF | Nativo |
| Validação inexistente | **FormRequests** por caso de uso (`rules()` + `authorize()`); erro por campo via `$errors` no componente `x-ds.input` | Nativo |
| Sem RBAC (role gravado e ignorado) | **Policies** por model + Gates; enum de papel no vínculo (`memberships.role`); middleware `can:` | Nativo |
| `TenantContext` estático | `TenantManager` (scoped singleton) + middleware `SetCurrentTenant` (a partir da sessão/token) + trait `BelongsToTenant` (preenche `tenant_id` ao criar + `TenantScope` global) + regra `exists` com `where tenant_id` + FK composta onde couber. Jobs carregam `tenant_id` explicitamente | Código próprio (sem pacote) |
| `AuditLogger` pouco usado | Tabela `audit_logs` append-only + `AuditLogger` + trait `Auditable` com observers (created/updated/deleted) + eventos explícitos de leitura de dado clínico; sem PII em `details` | Código próprio |
| Sem fila/agendador | Fila `database` drenada pelo scheduler: cron `*/15 * * * *` (mínimo da HostGator) chama `schedule:run`, que agenda `queue:work --stop-when-empty --max-time=120 --timeout=50`. Sem worker permanente (seção 6.5) | Nativo |
| Sessão/cache inexistentes | `SESSION_DRIVER=database` e `CACHE_STORE=database` (padrão do skeleton 13; locks atômicos e menos inodes que `file`) | Nativo |
| Sem envio de e-mail configurado | Mailer de provedor transacional (Postmark/Resend/Mailgun/SES via API HTTPS) com `failover` para o SMTP da HostGator; críticos enviados na hora | Nativo (+ transport do provedor) |
| Tabela `notifications` sem uso | Laravel Notifications (mail + database): não críticas pela fila (latência de até 15 min); críticas na hora | Nativo |
| Caminhos de foto/anexo como string | Disco privado (`storage/app/private`) servido por controller com Policy ou `temporarySignedRoute` + validação `mimes/max`; `storage:link` só se o servidor seguir symlinks (a confirmar) | Nativo |
| Sem logs estruturados | Canal `daily` com retenção (`LOG_DAILY_DAYS=14`) + request-id; JSON opcional; política de não logar PII | Nativo |
| `/health` estático + `bin/healthcheck.php` | Rota `/up` nativa + listener `DiagnosingHealth` (DB, heartbeat do scheduler, idade da fila) e versão/commit do build; monitor externo de uptime | Nativo |
| Testes `assert()` | **Pest** + `RefreshDatabase` em **MySQL 8** no CI, factories, mesma versão do servidor HostGator, testes Feature por história (positivo/negativo/cross-tenant) e Unit para calculadoras | `pestphp/pest` (+ plugin Laravel) |
| Seed TACO fixo (12 itens) a cada request | Comando `php artisan taco:import {arquivo} --versao=` idempotente com relatório; `DemoSeeder` só em `local` | Nativo |
| Views PHP + HTML dentro do controller | Blade layouts + componentes anônimos (`x-ds.button`, `x-ds.input` com label acima, erro ao lado e 44px) + Vite para o CSS (build no CI; não há Node na HostGator) | Nativo |

**Pacotes recomendados (mínimo):** `laravel/fortify` (auth sem impor frontend), `laravel/sanctum` (API do app) e `pestphp/pest`. **Opcionais com justificativa:** `larastan/larastan` (análise estática no CI, barato e pega erro de tipo) e Alpine.js (interações do editor de plano sem SPA). **Para a HostGator:** `league/flysystem-aws-s3-v3` (disco S3-compatível para backups externos e, depois, uploads) e, opcionalmente, `spatie/laravel-backup` (dump + envio externo + limpeza).
**Não recomendados agora:**
- Starter kits oficiais: usam Tailwind/React/Livewire e conflitariam com o CSS do DS. O Laravel 13 usa Fortify nos kits, então usar Fortify direto entrega o mesmo backend.
- `stancl/tenancy`: multi-banco é desnecessário para um banco único com `tenant_id`.
- `spatie/laravel-permission`: as Policies resolvem o MVP; reavaliar na NI2-123.
- Pacotes de auditoria prontos: o audit de leitura clínica e o controle de PII ficam mais simples em código próprio.
- Horizon, Reverb, Octane e Pulse na fase 1: exigem processo contínuo e/ou Redis, inviáveis na HostGator compartilhada (seção 11).

*Fonte das versões:* documentação oficial do Laravel 13 (lançado em 17/03/2026; PHP 8.3–8.5; kits usam Fortify).

---

## 6. Hospedagem: HostGator compartilhada (fase 1)

Decisão do Maicon (v1.1): a fase 1 roda em **HostGator Brasil, hospedagem compartilhada (cPanel), PHP 8.3**, mantendo Laravel 13. Esta seção troca as premissas genéricas de infraestrutura da v1.0 pelas restrições reais da HostGator, pesquisadas em 07/10/2026 só em fontes públicas (seção 13). Nada foi testado numa conta HostGator. O que a documentação não confirma está marcado **A confirmar no painel**, com o modo de checar.

### 6.1 Resumo e plano recomendado

- **Viável para o MVP** (Blade + API Sanctum + fila em banco), com cinco condições: (1) conta em servidor com **MySQL 8.0** (há servidores compartilhados com MySQL 5.7); (2) **sem processo contínuo**: a fila é drenada pelo scheduler, e o cron mínimo é **15 min**; (3) **sem websockets**: chat e app por polling + push; (4) **e-mail por provedor transacional**; (5) **backups próprios fora da HostGator**.
- **Plano:** **M** como mínimo (domínio adicional + subdomínios ilimitados, 250 mil inodes, 100 GB) ou **Turbo** se o orçamento permitir (500 mil inodes, 5 vCPU, menos contas por servidor). **P** funciona via subdomínio (sem domínio adicional). **Start não**: 50 mil inodes, 5 subdomínios, 3 contas de e-mail.
- **Antes do primeiro deploy**, pedir ao suporte, por escrito: servidor AlmaLinux/MySQL 8.0, habilitação do SSH e localização do servidor (LGPD). Depois, rodar o spike da seção 6.13.
- **Staging** num subdomínio do mesmo plano divide os limites (25 processos, 25 conexões) com produção. Mantê-lo leve e desligado fora de uso, ou em outra conta.

### 6.2 Restrições da HostGator Brasil (compartilhada) — consultadas em 07/10/2026

| Item | O que a documentação diz | Status | Como verificar no painel | Impacto no ClyoNutri | Fontes |
|---|---|---|---|---|---|
| Versão do PHP | 8.3 disponível. Seleção por domínio em cPanel → "Alterar versão de PHP"/"Gerenciador MultiPHP", ou por `AddHandler application/x-httpd-ea-php83` no .htaccess. A tabela de compatibilidade marca 8.2–8.5 como disponíveis e 8.0/8.1 como indisponíveis no compartilhado; a página de versões lista 8.0–8.4. Binário de CLI documentado para cron: `/opt/cpanel/ea-php83/root/usr/bin/php`. | **Confirmado (8.3)** | cPanel → MultiPHP: conferir a versão do domínio do app. Via SSH: `php -v` e `/opt/cpanel/ea-php83/root/usr/bin/php -v` (o PHP padrão do shell pode ser outro; o guia oficial ajusta o `.bashrc`). | Atende ao Laravel 13 (PHP ≥ 8.3). Fixar a mesma versão no domínio, no cron e no SSH, e travar `config.platform.php` no composer.json. | H1, H2, H6, H8, H11, L1 |
| Extensões PHP exigidas pelo Laravel 13 | A tabela de compatibilidade cita cURL, PDO/pdo_mysql, mbstring, tokenizer, iconv, SimpleXML, GD e ImageMagick. **fileinfo, dom, intl e zip não aparecem.** | **A confirmar no painel** | Via SSH: `/opt/cpanel/ea-php83/root/usr/bin/php -m`. Alternativa: `phpinfo()` temporário, apagado logo depois. | Sem `fileinfo`, a validação de MIME de upload (NI2-106) quebra. Checar antes de contratar/iniciar. | H2, L1 |
| OPcache | Divergente: a página de versões lista Zend OPcache no compartilhado; a tabela de compatibilidade marca módulos de opcode cache como indisponíveis no compartilhado ("somente dedicados"). | **A confirmar no painel** | `php -i \| grep opcache.enable` (CLI) e `phpinfo()` (web). | Desempenho. Sem OPcache, o ganho de `optimize` cai e a latência sobe. | H1, H2 |
| SSH | Disponível em Start, P, M e Turbo ("Acesso SSH" nos recursos técnicos), **habilitado por solicitação ao suporte**. Porta 2222, usuário e senha do cPanel. Cada comando consome recursos do plano e pode ser encerrado. | **Confirmado** | Pedir a habilitação pelo suporte; testar `ssh -p 2222 usuario@servidor`; cadastrar chave SSH no cPanel para o CI. | Viabiliza deploy por rsync e `php artisan` no servidor. Sem bloqueio no plano mais barato, mas depende de chamado. | H9, H10, H17–H20 |
| Composer | O suporte EUA diz que o Composer vem instalado nos compartilhados; o guia BR do Laravel instala `composer.phar` via curl. Não há confirmação BR de Composer global. | **A confirmar no painel** | Via SSH: `composer -V`. | Irrelevante no plano: `vendor/` é gerado no CI e enviado pronto. | H11, H26 |
| Node.js / npm | Incompatível nos compartilhados (só VPS/dedicado). | **Confirmado** | — | Assets do Vite sempre construídos no CI ou localmente; `public/build` vai no artefato. | H2 |
| Cron (intervalo mínimo) | **Mínimo de 15 minutos** nos planos compartilhados (artigo de cron e Termos §9.10). `* * * * *` não é permitido. | **Confirmado** | cPanel → Trabalhos Cron. | O scheduler roda a cada 15 min, e isso vale também para fila, lembretes e jobs. O cron por minuto pedido no plano **não é possível** aqui. | H7, H22 |
| Processos longos, Redis e websockets | Termos §9.3 proíbem daemons/aplicações stand-alone. Limite de 25 processos simultâneos por cPanel. memcached incompatível; Redis não aparece na lista. Portas de entrada nunca são abertas no compartilhado. | **Confirmado (Redis: a confirmar; assumir indisponível)** | Perguntar ao suporte sobre Redis; não há o que configurar. | Sem `queue:work` permanente, Supervisor, Horizon, Reverb/websockets ou Octane. Fila = database; tempo real = polling. | H2, H3, H16, H22 |
| Banco de dados (motor e versão) | **MariaDB não é oferecido.** O MySQL depende do servidor: **5.7 nos servidores CentOS** (prefixos br00 e sh-pro00) e **8.0 nos AlmaLinux** (sh0000, rs000, sh-pro00000). A página de versões cita 8.0.42-33 e a de especificações descreve o compartilhado como CentOS 7.9. | **Varia por servidor (confirmado); versão da conta a confirmar** | phpMyAdmin → página inicial ("Versão do servidor") ou `SELECT VERSION();`; nome do servidor em cPanel → Informações gerais. | **Bloqueio se cair em MySQL 5.7**: o alvo é MySQL 8. O Laravel 13 aceita 5.7+, mas CHECK, defaults por expressão, CTE e window functions só existem no 8.0. Pedir servidor AlmaLinux/MySQL 8 antes de subir. | H1, H2, H23, L2 |
| Tamanho máximo do banco | Não divulgado. Os planos P, M e Turbo listam "MySQL ilimitados" (quantidade de bancos); o armazenamento total é 50/100/100/150 GB (Start/P/M/Turbo). | **A confirmar no painel** | Perguntar ao suporte se há limite por banco; acompanhar o tamanho em cPanel → Bancos de dados MySQL. | Monitorar o crescimento; o tamanho do dump é gatilho para sair (seção 11). | H17–H20 |
| Conexões MySQL simultâneas | **25** (Start, P, M, Business, Plus e Turbo; não ajustável); `wait_timeout` de 3600 s. A página do Turbo diz "até 50" no texto e 25 na tabela. | **Confirmado (25); Turbo divergente** | Perguntar ao suporte se o Turbo tem 25 ou 50. | Capacidade de requisições simultâneas e de polling do app. Erro `max_user_connections` é gatilho para sair. | H4, H20 |
| CPU, memória e processos | Até 25% dos recursos do servidor (CPU, memória, disco, rede) por períodos ≥ 90 s; acima disso pode haver suspensão. 25 processos simultâneos por cPanel. vCPU: 2 (P/M) e 5 (Turbo). A RAM por conta não é divulgada. | **Confirmado** | cPanel → Estatísticas/uso de recursos (nome exato no painel a confirmar). | Jobs curtos e em lotes; nada de import/export pesado síncrono; **caixas IMAP também contam processos** (usar e-mail corporativo fora desta conta). | H3, H18–H20, H22 |
| Inodes (arquivos) | Start 50 mil; P, M, Business e Plus 250 mil; Turbo 500 mil. | **Confirmado** | cPanel → Estatísticas → "Quantidade de arquivos (Inodes)". | `vendor/` × número de releases, logs, sessões/cache em arquivo e uploads contam. Start é inviável. | H3, H17–H20, H22 |
| memory_limit / max_execution_time | Máximos documentados: `memory_limit` 512M, `max_execution_time` 60 s, `max_input_time` 60 s, `post_max_size` 516M, `upload_max_filesize` 512M (ajuste por "Editar PHP INI" ou `.user.ini`). Outro artigo diz padrão de 64 MB, ajustável até 256 MB (divergente). O limite do PHP CLI (cron/SSH) não é documentado. | **Confirmado (máximo 60 s web); valores efetivos e CLI a confirmar** | `phpinfo()` (web) e `php -i \| grep -E 'memory_limit\|max_execution_time'` (CLI). | Requisição web ≤ 60 s; exportações e importações via job em lotes. | H5, H25 |
| Document root | Ao adicionar domínio ou subdomínio no cPanel → Domínios, o campo "Document Root" é editável. Domínio adicional só nos planos M, Turbo e Business; o plano P tem 1 domínio + subdomínios ilimitados; Start até 5 subdomínios. Mudar o document root do **domínio principal** não está documentado no suporte BR. O guia oficial do Laravel usa `.htaccess` com `RewriteRule ^(.*)$ public/$1`. | **Confirmado (adicional/subdomínio); domínio principal a confirmar** | cPanel → Domínios → criar ou gerenciar: conferir se o Document Root aceita `/home/USUARIO/clyonutri/current/public`. | Servir o app por subdomínio/domínio adicional com docroot em `public/`; não usar a regra de rewrite com o app dentro de `public_html`. | H11, H12, H17–H19 |
| Links simbólicos (symlinks) | Não documentado no suporte BR. | **A confirmar no painel** | Criar um link de teste em `public/` e acessá-lo; criar o docroot apontando para um caminho com symlink (`current`) e acessar. | Define se dá para usar `storage:link` e trocar release por `ln -sfn` (deploy atômico). Há alternativas sem symlink. | — |
| SSL | Let's Encrypt gratuito, ativado automaticamente em domínios e subdomínios da conta; instalação em até 24 h após o DNS propagar. | **Confirmado** | cPanel → SSL/TLS Status (conferir emissão). | Reservar 24–48 h antes do go-live; forçar HTTPS na aplicação. | H13, H17–H20 |
| Envio de e-mail | Compartilhado: limite **dinâmico, por reputação, não divulgado**. Nas primeiras 24 h da conta: até 25 e-mails/hora por domínio (Termos §5). E-mail em massa proibido. Portas SMTP 465/587/26 na hospedagem; "Sendgrid SMTP" consta como compatível. | **Confirmado (o limite numérico não é divulgado)** | Não há painel com o limite. Monitorar devoluções. | Notificações e e-mails transacionais via provedor externo (API HTTPS); SMTP da HostGator só como failover. | H3, H14, H15, H2, H22 |
| Backups da HostGator | Rotina diária, semanal e mensal, **sobrescrita** (24 h / 7 dias / 30 dias), feita entre 18h e 5h BRT, para garantia interna. Restauração via suporte, com **taxa de R$ 200** segundo o artigo de limitações. Backup completo pelo cPanel só com < 10 GB e **apenas 1 backup armazenado**. Termos proíbem usar a hospedagem como backup. A HostGator não se responsabiliza pelos dados. | **Confirmado** | cPanel → Backup (gerar e baixar); Portal do Cliente → Backup. | Não serve como estratégia. Dumps próprios cifrados com cópia externa e ensaio de restauração (NI2-108). | H3, H17–H21, H22 |
| Git e transferência | "Cliente Git" e rsync compatíveis; "Servidor Git" incompatível. SFTP/SSH na porta 2222; FTPS na 990. | **Confirmado (cPanel Git Version Control: a confirmar)** | cPanel → procurar "Git". | Deploy por rsync sobre SSH a partir do CI; `git pull` no servidor é possível, mas não recomendado (exigiria build no servidor). | H1, H2, H15 |
| Portas e rede | Portas de entrada nunca abertas no compartilhado; portas altas de **saída** podem ser liberadas sob solicitação. MySQL remoto na 3306. | **Confirmado** | Chamado ao suporte para a porta de saída necessária. | Webhooks de entrada via HTTPS (443) funcionam; servidor de websockets não. | H15, H16 |
| ModSecurity | Presente (`mod_security` compatível). | **A confirmar comportamento** | Testar cedo PUT/DELETE/JSON da API e uploads; logs de erro no cPanel. | Falsos positivos podem bloquear chamadas da API do app. | H2 |
| Localização dos dados (LGPD) | A HostGator Latam diz ter migrado **parte** da infraestrutura para a Oracle Cloud em São Paulo. A localização de uma conta específica não está documentada. | **A confirmar no painel** | Perguntar ao suporte (por escrito) onde fica o servidor da conta. | Se estiver fora do Brasil, há transferência internacional a declarar na política de privacidade. | H24 |
| LGPD e conformidade | Termos §28: HostGator é operadora e o cliente é controlador; incidente comunicado pelo cliente com aviso prévio de 24 h à HostGator. "PCI Compliance" incompatível no compartilhado. | **Confirmado** | — | Nunca armazenar dados de cartão (usar tokenização do gateway); política de privacidade cita o operador. | H2, H22 |
| SLA e suporte a aplicações | SLA de 99,9%/mês com crédito. Não há suporte a aplicações de terceiros (PHP etc.). A tabela de compatibilidade lista "Laravel (versões 10 e 11)"; o **Laravel 13 não aparece**. | **Confirmado** | — | Sem bloqueio técnico se PHP ≥ 8.3 e extensões estiverem presentes, mas o suporte não ajuda no app. | H2, H22 |

### 6.3 Topologia no servidor e document root

```text
/home/USUARIO/
  clyonutri/
    releases/<sha>/          app completo vindo do CI (com vendor/ e public/build)
    current -> releases/<sha> symlink do release ativo (se o Apache seguir; a confirmar)
    shared/.env              permissão 600 (fora de qualquer docroot)
    shared/storage/          app/private (uploads clínicos), framework/, logs/
  backups/tmp/               dump temporário, apagado após o envio externo
  public_html/               site institucional ou vazio (não contém o app)
```

| Opção | Como | Quando usar |
|---|---|---|
| **A — recomendada** | Subdomínio (`app.seudominio.com.br`) ou domínio adicional (planos M/Turbo) com **Document Root = `/home/USUARIO/clyonutri/current/public`**, definido em cPanel → Domínios. | Padrão. Só `public/` fica exposto. |
| B — domínio principal | App em `~/clyonutri/`; o deploy copia o conteúdo de `public/` para `public_html/` e ajusta o `index.php` (caminhos de `vendor/autoload.php` e `bootstrap/app.php`; `usePublicPath()`). | Se o app precisar ficar na raiz do domínio principal e o docroot não puder mudar (a confirmar). |
| C — não recomendada | Guia da HostGator: app inteiro dentro de `public_html` com `.htaccess` `RewriteRule ^(.*)$ public/$1`. | Evitar: `.env`, `storage/` e `vendor/` ficam dentro do webroot e ficam expostos se a regra falhar. Se for inevitável, negar dotfiles e diretórios internos no `.htaccess` raiz. |

### 6.4 Deploy (CI → HostGator)

Premissa: o CI (GitHub Actions ou similar) faz todo o build. O servidor nunca roda `npm` (não há Node) e não depende de Composer.

```bash
# 1) CI — testes com dependências de dev, em MySQL 8 (mesma versão do servidor)
composer install --no-interaction && vendor/bin/pest
# 2) CI — artefato de produção
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci && npm run build                       # Vite → public/build
rsync -az --delete --exclude=.git --exclude=node_modules --exclude=tests \
  -e "ssh -p 2222" ./ USUARIO@SERVIDOR:~/clyonutri/releases/$SHA/
# 3) Servidor (via SSH, com o PHP fixado)
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
cd ~/clyonutri/releases/$SHA
ln -s ~/clyonutri/shared/.env .env && rm -rf storage && ln -s ~/clyonutri/shared/storage storage
$PHP artisan down --retry=60                  # só se a migration não for compatível com o release anterior
$PHP artisan migrate --force
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache && $PHP artisan event:cache
ln -sfn ~/clyonutri/releases/$SHA ~/clyonutri/current
$PHP artisan up
# 4) CI — smoke test
curl -fsS https://app.seudominio.com.br/up   # + login de teste + checar 403/404 em /.env e /storage
```

- **Os symlinks `.env` e `storage` dentro do release** são resolvidos pelo PHP, não pelo Apache. Funcionam mesmo que o Apache não siga links. Só o `current` (docroot) e o `public/storage` dependem do Apache seguir links (**a confirmar**).
- **Sem symlink no docroot:** o rsync vai direto para um diretório fixo `~/clyonutri/app/` entre `artisan down` e `artisan up`. A janela é curta e o deploy não é atômico. O rollback reenvia o artefato anterior, guardado no CI (manter os 3 últimos).
- **Rollback:** reapontar `current` para o release anterior e rodar o `optimize` nele. As migrations seguem **expand/contract** (adiciona → migra dados → remove só no release seguinte), para o release anterior funcionar no schema novo. `migrate:rollback` só em exceção; restaurar dump só em último caso.
- **Releases mantidos:** 2–3, para não estourar inodes (cada `vendor/` tem milhares de arquivos; medir no spike com `find releases/<sha> | wc -l`).
- **Fallback sem SSH** (emergência): zip enviado por FTPS (porta 990) e extraído no Gerenciador de Arquivos. Migrations por um cron de execução única, removido depois. **Nunca** expor rota web que rode `migrate`.
- **Chave SSH do CI** cadastrada no cPanel, de uso exclusivo do deploy. Senha do cPanel fora do CI. 2FA no cPanel.

### 6.5 Agendador e fila (sem worker permanente)

O cron mínimo na HostGator compartilhada é **15 minutos** (Termos §9.10). Por isso o `* * * * * php artisan schedule:run` da v1.0 **não é permitido**. Entrada única no cPanel → Trabalhos Cron:

```cron
*/15 * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/USUARIO/clyonutri/current/artisan schedule:run >> /dev/null 2>&1
```

```php
// routes/console.php — tudo em múltiplos de 15 min
Schedule::command('queue:work database --stop-when-empty --max-time=120 --timeout=50 --tries=3')
    ->everyFifteenMinutes()->withoutOverlapping(15);
Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()))->everyFifteenMinutes();
Schedule::command('backup:database')->dailyAt('03:30')->timezone('America/Sao_Paulo')->withoutOverlapping(); // comando próprio (6.8)
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->daily();
Schedule::command('model:prune')->daily();
```

- `--stop-when-empty` encerra o worker quando a fila esvazia; `--max-time` limita a execução; `--timeout` (50 s) fica abaixo do `retry_after` padrão (90 s) da conexão database. Jobs devem ser **curtos e em lotes** (ex.: 500 linhas por job): o limite é 25% dos recursos por ≥ 90 s e 25 processos.
- **Consequência de produto:** tudo que vai para a fila pode levar **até ~15 min**. Lembretes e notificações têm granularidade de 15 min.
- **Envio imediato** para mensagens críticas (verificação de e-mail, reset de senha, convite do paciente): envio síncrono para a API do provedor, com timeout curto, ou `defer()` do Laravel 13 (roda após a resposta; o ganho de latência depende do handler PHP da HostGator liberar a resposta antes, **a confirmar**). Essas notificações não implementam `ShouldQueue`.
- Não usar `runInBackground()` (cria processos extras dentro do limite de 25).
- **Não recomendado:** pinger externo chamando uma rota a cada minuto para "simular" cron por minuto. Contorna a regra dos 15 min e pode ser lido como uso incompatível com ambiente compartilhado (Termos §9.11).

### 6.6 Cache, sessão, logs e health

- `SESSION_DRIVER=database`, `CACHE_STORE=database` e `QUEUE_CONNECTION=database`, que já são o padrão do skeleton do Laravel 13. O cache em banco dá locks atômicos (para `withoutOverlapping` e rate limit) e evita milhares de arquivos (inodes). `BROADCAST_CONNECTION=log` na fase 1.
- Logs: `LOG_STACK=daily` com `LOG_DAILY_DAYS=14` e `LOG_LEVEL=warning` em produção; formatter JSON opcional. Nunca logar PII nem conteúdo clínico.
- Health: rota **`/up`** nativa + listener `DiagnosingHealth` que falha se o banco não responder, se o heartbeat do scheduler tiver mais de 20 min ou se o job mais antigo da fila tiver mais de 30 min. Um monitor externo de uptime chama `/up` a cada 5 min e alerta. Error tracking externo é opcional (com scrub de PII e DPA).
- Sem Horizon, Telescope ou Pulse em produção: exigem Redis e/ou processo contínuo, ou pesam no banco compartilhado.

### 6.7 Arquivos e storage privado

- Dados clínicos e anexos ficam em `storage/app/private` (disco `local`, fora de qualquer docroot). São servidos por controller com Policy, ou por `URL::temporarySignedRoute` com expiração. Nunca em `public/`.
- Mídia pública (logo, foto do perfil público): `storage:link` só se o Apache seguir o symlink (**a confirmar**). Alternativa sem symlink: rota que entrega o arquivo com `Cache-Control` longo.
- Uploads: validação `mimes`/`max` (exige a extensão `fileinfo`, **a confirmar**); imagens redimensionadas/comprimidas (GD/ImageMagick constam como disponíveis); `upload_max_filesize`/`post_max_size` reduzidos via `.user.ini` (ex.: 10M).
- Uploads contam inodes e espaço. Migrar para storage S3-compatível (Flysystem S3) quando o volume crescer; é só trocar o disco, sem mudança de código.

### 6.8 Backups e restauração

- Os backups da HostGator **não contam** como estratégia: são sobrescritos (24 h / 7 d / 30 d), a restauração é via suporte com taxa de R$ 200 e a conta guarda no máximo 1 backup do cPanel. Os Termos proíbem usar a hospedagem como armazenamento de backup e eximem a HostGator pelos dados.
- **Dump diário próprio** (comando artisan agendado, 03:30 BRT): `mysqldump --single-transaction --quick --no-tablespaces` (a última opção evita exigir o privilégio PROCESS), senha via `--defaults-extra-file` e nunca na linha de comando, `gzip` + cifragem (GnuPG consta como disponível), envio para **storage externo S3-compatível** (região no Brasil, versionamento ligado) e remoção da cópia local. A disponibilidade do binário `mysqldump` está **a confirmar** (`which mysqldump`). Opcional: `spatie/laravel-backup` faz dump + envio + limpeza.
- Uploads privados copiados para o mesmo storage externo (incremental).
- Retenção externa sugerida: 7 diários, 4 semanais e 12 mensais. Dump extra antes de cada deploy com migration.
- **Ensaio de restauração mensal**: baixar o dump, restaurar num banco de staging, rodar `migrate:status`, contagens e smoke test, e registrar o tempo (RTO) e a idade do dado (RPO). Alvo sugerido: RPO ≤ 24 h e RTO ≤ 4 h, a validar com o Maicon.

### 6.9 E-mail

- O limite de envio no compartilhado é **dinâmico e não divulgado** (25/h por domínio nas primeiras 24 h da conta) e e-mail em massa é proibido. Usar **provedor transacional via API HTTPS** (drivers nativos do Laravel: Postmark, Resend, Mailgun ou SES) com `MAIL_MAILER=failover` (provedor → SMTP da HostGator).
- Domínio autenticado (SPF, DKIM e DMARC) no DNS. Escolher o provedor com DPA/LGPD (é operador de dados).
- Sem conteúdo clínico no corpo do e-mail: só aviso + link para área logada.
- Caixas de e-mail da equipe **fora desta conta cPanel** (Google Workspace, Microsoft 365 ou o Titan da própria HostGator, que roda fora do servidor de hospedagem). Processos IMAP contam no limite de 25 processos e podem derrubar o site.

### 6.10 MySQL na HostGator: compatibilidade das migrations

A meta continua **MySQL 8.0** (servidor AlmaLinux). Se a conta cair em MySQL 5.7, ver a decisão 1 da seção 9. Regras para as migrations:

| Recurso | MySQL 5.7 | MySQL 8.0 | Regra no projeto |
|---|---|---|---|
| Colunas JSON | Sim (5.7.8+) | Sim | Permitido para dados auxiliares; o que for filtrado ou indexado vira coluna própria. |
| Default em JSON/TEXT/BLOB | Não | Só como expressão, desde 8.0.13 [M2] | Não declarar default no banco; default no model (`$attributes`). |
| CHECK constraints | Aceitas e **ignoradas** | Aplicadas desde 8.0.16 [M1] | Regra sempre no FormRequest/domínio; CHECK no banco só como defesa extra, depois de confirmar 8.0.16+. |
| Colunas geradas | Sim | Sim | Permitidas (STORED/VIRTUAL). Índice funcional só no 8.0.13+: evitar. |
| CTE e window functions | Não | Sim | Permitidas só com 8.0 confirmado; relatórios precisam de alternativa se for 5.7. |
| Triggers | Com binlog ativo, criar trigger/função pode exigir SUPER (ou `log_bin_trust_function_creators=1`) [M3] | Idem (SET_USER_ID/SUPER) | **Não depender de trigger** (privilégio indisponível em compartilhada, a confirmar). Imutabilidade no app, com defesas na seção 9. |
| DDL em transação | Não (commit implícito) | Não | Uma alteração estrutural por migration; dump antes de `migrate --force`; expand/contract. |
| Collation | Padrão utf8mb4_general_ci | Padrão utf8mb4_0900_ai_ci | Fixar `DB_COLLATION=utf8mb4_unicode_ci` (padrão do config do Laravel) no CI e em produção. |

### 6.11 Segurança específica da hospedagem

- Só `public/` exposto (opção A da 6.3); `.env` em `shared/` com permissão 600; diretórios 755 e arquivos 644 ou mais restritivos (Termos §4.6); `APP_ENV=production` e `APP_DEBUG=false`.
- **PHP fixado** em 8.3 no MultiPHP para o domínio do app, no cron (caminho completo `/opt/cpanel/ea-php83/root/usr/bin/php`) e no SSH (`.bashrc`). Se o MultiPHP gravar o handler no `.htaccess` do docroot (**a confirmar**), versionar esse bloco no `public/.htaccess` do repositório. Senão, o rsync sobrescreve e o PHP volta ao padrão do servidor. `composer.json` com `config.platform.php` = versão do servidor.
- Manter o `public/.htaccess` do Laravel: repassa o header `Authorization` (Sanctum) e desliga listagem de diretório.
- HTTPS forçado após o AutoSSL emitir; HSTS só depois de estável.
- Testar cedo se o ModSecurity bloqueia PUT/DELETE/JSON da API e uploads (falso positivo).
- Sem arquivos `phpinfo()` esquecidos; sem rota de debug; chave SSH do CI restrita ao deploy.
- Nada de dado de cartão no servidor ("PCI Compliance" consta incompatível no compartilhado): usar tokenização do gateway.
- LGPD: a HostGator é operadora (Termos §28); confirmar por escrito a localização do servidor e citar o operador e uma eventual transferência internacional na política de privacidade.

### 6.12 Impacto nas histórias

| História | Título | Épico | Ajuste para HostGator (fase 1) |
|---|---|---|---|
| NI2-36 | [Fundação] Criar aplicação ClyoNutri independente | NI2-1 | Estrutura para compartilhada: app fora do `public_html` (`/home/USUARIO/clyonutri/`), docroot em `public/` via subdomínio/domínio adicional, `.env` fora do webroot. |
| NI2-37 | [Fundação] Criar banco e migrations próprios | NI2-1 | Migrations compatíveis com MySQL 8.0 do servidor HostGator; não depender de trigger; uma alteração estrutural por migration (DDL não é transacional no MySQL); ver seção 6.10. |
| NI2-38 | [Fundação] Configurar ambientes, build, deploy e rollback | NI2-1 | Deploy HostGator: CI gera artefato com `vendor/` (`composer install --no-dev -o`) e `public/build` (Vite), envia por rsync/SSH (porta 2222) para `releases/<sha>` e roda `migrate --force` + `optimize`; troca de release por symlink se o Apache seguir (a confirmar), senão diretório fixo com `down/up`. Fallback zip+FTPS só em emergência. Staging = subdomínio + banco separado. Rollback = release anterior + migrations expand/contract. |
| NI2-40 | [IAM] Cadastro e verificação de conta profissional | NI2-2 | E-mail de verificação enviado na hora (síncrono ou `defer()`), não pela fila de 15 min; via provedor transacional. |
| NI2-42 | [IAM] Recuperação e redefinição de senha | NI2-2 | E-mail de reset enviado na hora (síncrono ou `defer()`), via provedor transacional; não depender da fila de 15 min. |
| NI2-44 | [IAM] Isolamento multi-tenant e proteção IDOR | NI2-2 | Sem impacto de hospedagem; testes cross-tenant rodam no CI (MySQL 8). |
| NI2-71 | [Alimentos] Importar referência compatível com TACO | NI2-8 | Import TACO por comando artisan via SSH ou job em lotes (respeitar 60 s web e o limite de 25% de CPU); nunca na requisição. |
| NI2-73 | [Alimentos] Busca eficiente no catálogo | NI2-8 | FULLTEXT InnoDB funciona em MySQL 5.7 e 8.0; validar no servidor real. |
| NI2-86 | [Paciente] Convite e ativação segura | NI2-11 | Convite por e-mail via provedor transacional, enviado na hora; link assinado com expiração. |
| NI2-89 | [API] API versionada do paciente | NI2-11 | API em Sanctum funciona no compartilhado (tokens no banco). Rate limit com cache `database`; manter a regra do `.htaccess` do Laravel que repassa o header Authorization; testar cedo contra ModSecurity; sem websockets. |
| NI2-90 | [Mobile] Base React Native + Expo + TypeScript | NI2-11 | App consome `/api/v1` por HTTPS; sincronizar por polling e push (Expo) — sem conexão persistente com o servidor. |
| NI2-136 | [Paciente][P1] Exames, documentos e orientações com acesso seguro | NI2-11 | Documentos em disco privado com URL assinada; atenção a inodes e ao tamanho; storage externo quando crescer. |
| NI2-138 | [Paciente][P1] Canal seguro de comunicação com nutricionista | NI2-11 | Canal seguro fase 1 por polling + push; sem websockets na compartilhada. |
| NI2-139 | [Paciente][P1] Offline, sincronização e consistência entre app e portal | NI2-11 | Sincronização offline por lotes idempotentes (chave de idempotência) via HTTPS; nada de conexão persistente. |
| NI2-95 | [Notificações] Eventos, preferências e entregas | NI2-12 | Notificações: canais database + mail. Fila `database` processada pelo scheduler a cada 15 min (latência de até 15 min); críticas (verificação, reset, convite) na hora. E-mail via provedor transacional (API HTTPS) com SPF/DKIM/DMARC; SMTP da HostGator só como failover. |
| NI2-96 | [Analytics] Métricas de adesão com privacidade | NI2-12 | Agregações de métricas em job noturno pelo scheduler, em lotes. |
| NI2-100 | [Financeiro] Gerar recibo | NI2-13 | PDF com biblioteca PHP pura (ex.: dompdf) ou CSS de impressão; não há Node/Chrome no servidor. |
| NI2-101 | [Financeiro] Relatórios e exportação CSV | NI2-13 | CSV por `streamDownload` (≤ 60 s); exportação grande via job + link assinado temporário. |
| NI2-104 | [LGPD] Exportação, exclusão e anonimização | NI2-14 | Export/anonimização LGPD como jobs em lotes pela fila database (scheduler a cada 15 min); arquivo de exportação em disco privado com URL assinada e expiração. |
| NI2-105 | [Segurança] Testes IDOR, cross-tenant, CSRF, XSS e SQLi | NI2-14 | Suite de segurança roda no CI (MySQL 8); incluir teste de que `.env`, `storage/` e `vendor/` não são acessíveis pela web no smoke test do deploy. |
| NI2-106 | [Segurança] Hardening de arquivos, uploads, secrets e webroot | NI2-14 | Hardening HostGator: só `public/` exposto (docroot de subdomínio/domínio adicional); `.env` fora do webroot (permissão 600/640; Termos exigem ≤ 644); diretórios 755; uploads em `storage/app/private` servidos por controller com Policy/URL assinada; `upload_max_filesize` reduzido via `.user.ini`; `APP_DEBUG=false`; não usar a regra de rewrite com o app dentro de `public_html`. |
| NI2-107 | [Operação] Observabilidade e health checks | NI2-14 | Observabilidade HostGator: `/up` + listener `DiagnosingHealth` (DB, heartbeat do scheduler < 20 min, idade do job mais antigo); monitor externo de uptime; logs `daily` com retenção (ex.: 14 dias), JSON opcional; sem Horizon/Telescope em produção. |
| NI2-108 | [Operação] Backup, restore e migration rehearsal | NI2-14 | Backup HostGator: dumps próprios do MySQL (diário, `--single-transaction`), cifrados, enviados para storage externo S3-compatível e removidos do servidor; cópia dos uploads privados; retenção externa (ex.: 7 diários/4 semanais/12 mensais); ensaio de restauração mensal em banco de staging com checklist. Backups da HostGator são só a última linha de defesa (sobrescritos; restauração com taxa de R$ 200). |
| NI2-109 | [QA] E2E Golden Path profissional e paciente | NI2-14 | E2E roda no CI/local, não no servidor compartilhado; smoke test pós-deploy contra produção. |
| NI2-110 | [QA] Performance, acessibilidade e responsividade | NI2-14 | Medir no servidor real: OPcache (a confirmar), `optimize`, índices, 25 conexões MySQL e 25 processos; carga de polling do app; meta de p95 a definir. |
| NI2-111 | [Go-Live] UAT, release gate, deploy, smoke test e rollback | NI2-14 | Go-live HostGator: confirmar MySQL 8.0 (AlmaLinux), PHP 8.3 fixado no domínio e no cron, SSH habilitado, SSL emitido (até 24 h), cron `*/15` ativo, provedor de e-mail com domínio autenticado, backup externo testado, smoke test e rollback ensaiados; evitar depender do e-mail da HostGator nas primeiras 24 h (limite de 25/h). |
| NI2-115 | [P0][Benchmark] Diário alimentar com fotos, sintomas e contextualização | NI2-112 | Fotos do diário: redimensionar/comprimir no upload (GD/ImageMagick disponíveis); uploads contam inodes; mover para storage S3-compatível quando o volume crescer. |
| NI2-118 | [P1][Benchmark] Chat seguro profissional-paciente e central de comunicação | NI2-112 | Chat fase 1 sem websockets: polling com cursor (ex.: a cada 15–30 s só com a conversa aberta) + push/e-mail de nova mensagem; tempo real só após sair da compartilhada (ou serviço externo, com DPA). |
| NI2-120 | [P1][Benchmark] Migração assistida de concorrentes por CSV e documentos | NI2-112 | Importação CSV: prévia síncrona pequena; processamento por job em lotes pela fila database; relatório ao final. |
| NI2-121 | [P1][Benchmark] Agendamento público e página profissional premium | NI2-112 | Confirmações por e-mail via provedor; rate limit no agendamento público (cache database). |
| NI2-122 | [P1][Benchmark] Cobrança integrada, pacotes e recorrência | NI2-112 | Webhooks do gateway via HTTPS (entrada 443 funciona); recorrência pelo scheduler (granularidade de 15 min); sem dados de cartão no servidor (PCI incompatível). |
| NI2-124 | [P1][Benchmark] Integração com calendário e teleconsulta | NI2-112 | Teleconsulta só via provedor externo; WebRTC/servidor de mídia não roda em compartilhada. |
| NI2-125 | [P1][Benchmark] Health Connect e Apple Health com autorização explícita | NI2-112 | Health sync em lotes via API, processado por jobs; sem processos contínuos. |

### 6.13 Spike de validação na HostGator (antes da NI2-1 terminar) — esforço P

1. Chamado ao suporte: habilitar SSH e pedir, por escrito, versão do MySQL do servidor (exigir 8.0/AlmaLinux), localização do datacenter, se há Redis, limite de tamanho por banco, conexões do Turbo (25 ou 50) e se o docroot do domínio principal pode mudar.
2. Via SSH: `php -v`; `/opt/cpanel/ea-php83/root/usr/bin/php -m` (ctype, curl, dom, fileinfo, filter, hash, mbstring, openssl, pcre, pdo_mysql, session, tokenizer, xml, gd, zip, intl); `php -i | grep -E 'opcache.enable|memory_limit|max_execution_time'`; `composer -V`; `which mysqldump gpg rsync`.
3. phpMyAdmin: anotar a "Versão do servidor"; num banco de teste, tentar `CREATE TRIGGER`, só para registrar se é possível.
4. Criar subdomínio de teste com Document Root em `~/clyonutri/current/public` (via symlink) e testar se um link simbólico dentro de `public/` é servido.
5. Deploy de um Laravel 13 vazio pelo fluxo da 6.4: `/up`, `migrate --force`, cron `*/15` com heartbeat e um job de teste processado pela fila database.
6. E-mail pelo provedor escolhido (sandbox) com SPF/DKIM; chamada da API com token Sanctum (header Authorization e ModSecurity).
7. Dump → cifragem → envio externo → restauração num banco de teste.
8. Medir inodes de um release, tempo de resposta e uso de recursos no painel.
9. Resultado: **go/no-go da HostGator** registrado no Jira, com as linhas "A confirmar" da 6.2 atualizadas.

---

## 7. Estrutura alvo do projeto Laravel

```text
app/
  Domain/
    Tenancy/        Tenant, Membership, enums de papel
    Identity/       User, Professional, Actions (CreateNewUser)
    Patients/       Patient, Consent, PatientService
    Clinical/       Anamnesis (append-only), NutritionRecord, ClinicalNote, ClinicalSummary
    Anthropometry/  Assessment, AnthropometryCalculator (puro)
    Foods/          Food, HouseholdMeasure, NutritionCalculator (puro), TacoImporter
    MealPlans/      MealPlan, MealPlanVersion, Meal, Item, Substitution, Template, ShoppingListBuilder (puro)
    Scheduling/     Appointment, AppointmentStatus (enum), transições
    Engagement/     HydrationLog, Checkin, notificações
    Finance/        Receivable, Payment (ledger), Receipt
    Privacy/        DataSubjectRequest, ExportSubjectData, AnonymizePatient
    (cada domínio: Models/, Actions/, Policies/, Events/, Enums/)
  Http/
    Controllers/Web/…   Controllers/Api/V1/…
    Requests/…          Resources/V1/…
    Middleware/SetCurrentTenant.php
  Support/
    Tenancy/  TenantManager, TenantScope, BelongsToTenant
    Audit/    AuditLogger, Auditable
resources/
  css/design-system.css          (porta do clinical-botanical.css)
  views/layouts/professional.blade.php, patient.blade.php
  views/components/ds/*.blade.php
  views/professional/…  views/patient/…  views/auth/…
routes/web.php  routes/api.php (v1)  routes/console.php
database/migrations  database/factories  database/seeders (DemoSeeder local)
tests/Unit (calculadoras)  tests/Feature/<Domínio>/… (incl. CrossTenantTest)
```

Pastas por domínio dentro de `app/` dispensam pacotes de módulos e mantêm o projeto enxuto. A disposição no servidor (releases, `shared/`, docroot) está na seção 6.3.

---

## 8. Plano de migração

### 8.1 Ordem por épico

| Ordem | Épico | Fase | Esforço | Depende de | Observação |
|---|---|---|---|---|---|
| 1 | NI2-1 — [P0] Fundação, autonomia e segregação do ClyoNutri | Fase 0 — Fundação | M | — | Projeto Laravel 13, CI com MySQL 8, ambientes, migrations-base com tenant_id. Antes: decisões da seção 9 fechadas e spike HostGator (seção 6.13) com hello-world Laravel 13 no servidor real. |
| 2 | NI2-2 — [P0] Identidade, autenticação, RBAC e multi-tenancy | Fase 1 — Identidade, tenancy e auditoria | G | NI2-1 | Fortify, Sanctum, papéis/Policies, TenantScope, AuditLogger (puxar NI2-103 para cá). E-mails de verificação/reset na hora, via provedor transacional. |
| 3 | NI2-3 — [P0] Design System Clinical Botanical e Application Shell | Fase 1 — Design System em Blade | M | NI2-1 | Paralelo ao NI2-2: portar CSS Premium, componentes x-ds.*, shell responsivo. Build do Vite no CI (sem Node na HostGator). |
| 4 | NI2-4 — [P0] Onboarding e perfil profissional do nutricionista | Fase 2 — Onboarding | M | NI2-2, NI2-3 | Perfil profissional, clínica/equipe, ativação. |
| 5 | NI2-5 — [P0] Gestão de pacientes e Perfil 360 | Fase 2 — Pacientes e Perfil 360 | M | NI2-2, NI2-3 | Inclui modelo de consentimento versionado (NI2-59). |
| 6 | NI2-6 — [P0] Prontuário e anamnese nutricional | Fase 3 — Prontuário e anamnese | G | NI2-5 | Append-only no app (sem depender de trigger na HostGator); ver code-review-NI2-6.md. |
| 7 | NI2-7 — [P0] Antropometria, avaliação e evolução clínica | Fase 3 — Antropometria | M | NI2-5 | Calculadora pura reaproveitada. |
| 8 | NI2-8 — [P0] Base de alimentos, nutrientes e porções | Fase 4 — Base de alimentos | M | NI2-1 | Import TACO reprodutível (comando via SSH, em lotes) + NutritionCalculator (corrigir bug). |
| 9 | NI2-9 — [P0] Plano alimentar e prescrição nutricional | Fase 4 — Plano alimentar | G | NI2-6, NI2-8 | Versionamento/publicação transacional. |
| 10 | NI2-10 — [P1] Agenda e jornada de consulta | Fase 5 — Agenda e consulta | M | NI2-5 | P1 no Jira, mas faz parte do MVP do Blueprint §2.2 (decidir). Lembretes com granularidade de 15 min (cron HostGator). |
| 11 | NI2-11 — [P0] Portal e App do Paciente — Jornada Nutricional 360 | Fase 5 — API e App do Paciente | G | NI2-2, NI2-9 | Subconjunto P0 (86–91, 131–135, 140–141) antes do go-live; P1 depois. Fase 1 sem websockets: polling + push. Provavelmente precisa ser fatiado. |
| 12 | NI2-12 — [P1] Engajamento, hidratação, check-ins e notificações | Fase 5 — Engajamento | M | NI2-11 | Notificações via fila database processada pelo scheduler a cada 15 min; e-mail por provedor externo. |
| 13 | NI2-13 — [P1] Financeiro essencial, recibos e relatórios | Fase 5 — Financeiro essencial | M | NI2-10 | Ledger imutável com estorno. |
| 14 | NI2-14 — [P0] Segurança, LGPD, qualidade, observabilidade e Go-Live | Fase 6 — Segurança, LGPD e Go-Live | M | todas | Contínuo: testes cross-tenant e audit entram em cada épico; aqui ficam LGPD, backup externo com ensaio de restore, hardening/observabilidade HostGator, UAT e release gate. |
| 15 | NI2-112 — [Benchmark 2026] Paridade competitiva e diferenciação premium | Fase 7 — Benchmark (pós-MVP) | G | MVP em produção | Estimar história a história no grooming; NI2-119/124/130 recomeçam do zero. Chat em tempo real e teleconsulta dependem de sair da compartilhada ou de serviço externo. |

**Ordem de grandeza do MVP (NI2-1 a NI2-14):** 10 épicos M e 4 G ≈ **22 a 54 semanas-dev** para um dev, com testes. Fases 1, 3 e 4 têm frentes paralelizáveis. É estimativa grosseira e deve ser refinada história a história no grooming. NI2-112 fica fora dessa conta (pós-MVP). A adequação à HostGator (deploy, backup externo, provedor de e-mail, observabilidade) está dentro de NI2-1 e NI2-14; o spike da seção 6.13 soma ~P.
**Gate de cada fase:** CI verde (Pest em MySQL 8, mesma versão do servidor HostGator), suíte cross-tenant do módulo passando, deploy em staging na HostGator e demo funcional para o PO.

### 8.2 Definition of Done por história (proposta)

- Critérios de aceite do Jira atendidos e conferidos no PR, que cita a chave `NI2-xx`.
- Rota, controller e view Blade (ou endpoint `/api/v1` + API Resource) utilizáveis de ponta a ponta. Serviço sem superfície não conta como pronto.
- FormRequest com validação e mensagens por campo. View segue o DS: label acima, erro ao lado, 44px, foco visível e navegação por teclado.
- Policy aplicada (`authorize`) e dado do tenant via `BelongsToTenant`. Teste negativo de outro tenant e de papel sem permissão.
- Ações sensíveis e leituras de dado clínico auditadas em `audit_logs`, sem PII nem conteúdo clínico em `details`/logs.
- Migrations com `down()`, índices compostos por `tenant_id` e FKs coerentes com a política de retenção (seção 9), compatíveis com o MySQL do servidor (seção 6.10).
- Respeita a HostGator: nenhuma requisição > 60 s, nada que dependa de processo contínuo, cron < 15 min ou websockets; trabalho pesado em job curto e em lotes.
- Testes Pest: Feature (feliz, validação, autorização, cross-tenant) e Unit para regras puras, verdes no CI com MySQL 8 (mesma versão do servidor).
- Sem dados simulados ou fixos no código de produção. Seeds de demo só em `local`.
- Revisão do PO (Maicon) com evidência (print/vídeo ou roteiro) anexada no Jira.

---

## 9. Decisões de modelo de dados a fechar antes da Fase 0

| Decisão | Recomendação |
|---|---|
| 1. Servidor MySQL de produção (HostGator) | **Primeira decisão.** Exigir conta em servidor AlmaLinux com **MySQL 8.0**, com confirmação por escrito do suporte, e rodar o CI na mesma versão. Se só houver MySQL 5.7: trocar de hospedagem ou limitar o projeto a 5.7 (sem CHECK, sem default por expressão, sem CTE/window functions). MariaDB não é oferecido no compartilhado. |
| Chave de isolamento: `tenant_id` × `clinic_id` | Usar **`tenant_id`** em todas as tabelas de negócio, referenciando `tenants` (a conta/organização). A NI2-123 introduz unidades e salas dentro de uma organização, e chamar o tenant de 'clinic' confundiria tenant com unidade. `clinic_unit_id` opcional fica para a NI2-123. |
| Tenant × unidade × profissional | `users` 1–N `memberships(tenant_id, user_id, role)`. `professionals` passa a ter `tenant_id` (hoje não tem). Autoria clínica = `professional_id` derivado da sessão, nunca do request. Hoje código e testes confundem `user_id` com `professional_id`. |
| Paciente como usuário | `patients.user_id` nulo até a ativação por convite (NI2-86). Papel `patient` com Policies que só liberam os próprios recursos. |
| Registros clínicos versionados e imutáveis | Anamnese, registro longitudinal e observações em modo **append-only**: correção = nova versão com `supersedes_id`; `UNIQUE(tenant_id, patient_id, version)`. Imutabilidade **no app**: model sem update/delete, observers que lançam exceção e Policy, mais cadeia de hash nos registros e em `audit_logs` conferida por comando diário. Na HostGator, trigger pode exigir SUPER com binlog ativo (indisponível em compartilhada, a confirmar), e o cPanel concede privilégios por banco, não por tabela. Para defesa no banco, só um segundo banco/usuário com INSERT/SELECT para as tabelas append-only (a confirmar). Versão publicada do plano é congelada (`meal_plan_versions`). |
| Soft delete × CASCADE | Trocar `ON DELETE CASCADE` por **`RESTRICT`** em `patient_id` nas tabelas clínicas e financeiras. `SoftDeletes` em pacientes (inativar ≠ apagar). Nunca cascatear a exclusão do tenant em produção; encerramento de conta vira processo de LGPD. |
| Anonimização LGPD e retenção | Definir com o jurídico o prazo de guarda do prontuário nutricional e o que é anonimizado: nome, contato, documento, data de nascimento e texto livre clínico. Hoje a anonimização não toca anamneses nem data de nascimento/gênero. Exportação e anonimização como jobs auditados a partir de `data_subject_requests`. **Prazo legal não verificado nesta análise.** |
| Consentimento | Tabela de documentos versionados (termo, finalidade) + aceite com versão, data, origem/IP e revogação. Nunca criar consentimento automaticamente, como faz hoje a importação CSV. |
| Identificadores públicos | PK `bigint` + **ULID/UUID público** em URLs e na API, para evitar enumeração. Hoje `patients.uuid` é hex de 32 caracteres e `users.uuid` é v4 com hífen (inconsistente). |
| Modelo nutricional | Nutrientes por **100 g** (base explícita) + tabela de medidas caseiras (g por unidade/colher). Fonte e versão por alimento (TACO x custom). Unidade incompatível = erro explícito. |
| Dinheiro e financeiro | Manter centavos inteiros (já correto). Pagamentos como **ledger imutável**: estorno = lançamento negativo com motivo e autor. `receipt_code` único por tenant. |
| Tempo | Gravar em UTC e exibir no timezone da clínica (campo novo em `tenants`). Hoje usa `CURRENT_TIMESTAMP` do servidor e `date('now')` do SQLite. Horários de negócio no scheduler com `timezone('America/Sao_Paulo')`. |
| Dados existentes a migrar | Não verifiquei se há dados reais: o banco local é SQLite e o `.env` não veio na cópia. Se só houver dados de demo, começar do zero sem script de migração de dados. |
| Criptografia de campos clínicos | Avaliar o cast `encrypted` no texto livre clínico. Ganha confidencialidade em repouso, mas perde busca/ordenação. Decidir por campo. |

---

## 10. Recomendações para o Jira (somente recomendações; nada foi alterado)

- Reabrir as 94 histórias 'Feito' cujo estado real não é Implementado (todas exceto NI2-39) e os épicos NI2-1 a NI2-10 e NI2-12 a NI2-14 e NI2-112. O estado sugerido por história está na planilha.
- Fechar NI2-15 a NI2-34 como duplicadas de NI2-35 a NI2-54, depois de copiar critérios exclusivos (se houver).
- Reescrever NI2-36 (e NI2-16) para 'Criar aplicação Laravel 13 independente': trocar PSR-4/front controller por projeto Laravel, config por ambiente e storage privado.
- Reescrever NI2-37 (e NI2-17) para migrations Laravel com `down()`, `tenant_id`, índices compostos e FKs RESTRICT.
- Reescrever NI2-44 (e NI2-24): trocar 'repositories recebem contexto tenant' por 'escopo global + validação de posse + Policies + testes cross-tenant'.
- Ajustar NI2-40, NI2-41 e NI2-42 (e NI2-20 a NI2-22) para citar Fortify (verificação de e-mail, reset, rate limit) em vez de implementação própria.
- Reescrever NI2-45 (e NI2-25) e renomear o épico NI2-3 para Design System Premium (Navy/Copper/Inter); o aceite atual cita os tokens Clinical Botanical.
- Reescrever NI2-89 para `/api/v1` com Sanctum (tokens por dispositivo, abilities), rate limit em cache database e testes IDOR em Pest, registrando que a fase 1 não tem websockets (polling + push). NI2-90: SDK atual do Expo (o projeto usa ~51).
- Ajustar NI2-105, NI2-107 e NI2-109 para Laravel e HostGator: Pest/MySQL 8 no CI, `/up` com heartbeat do scheduler e idade da fila, logs `daily` com retenção, monitor externo de uptime, E2E no CI e smoke test pós-deploy.
- Reescrever NI2-38 para o deploy HostGator: artefato do CI com `vendor/` e `public/build`, rsync via SSH (porta 2222), `migrate --force` + caches, staging em subdomínio, rollback por release anterior e migrations expand/contract.
- Acrescentar ao NI2-106: só `public/` exposto, `.env` fora do webroot, uploads em disco privado, permissões 755/644 e teste de que `/.env`, `/storage` e `/vendor` não são servidos.
- Reescrever NI2-108: dump diário próprio cifrado com cópia externa, retenção definida e ensaio mensal de restauração. Os backups da HostGator não contam para o aceite.
- Acrescentar ao NI2-95 (e épico NI2-12): latência de até 15 min para notificações não críticas, envio imediato das críticas e provedor de e-mail transacional com DPA.
- Acrescentar ao NI2-111 o checklist de go-live HostGator: MySQL 8, PHP fixado, SSH, SSL, cron `*/15`, e-mail autenticado, backup testado, smoke test e rollback.
- Anotar em NI2-118 e NI2-138 que a fase 1 usa polling + push e que websockets dependem de sair da compartilhada (seção 11).
- Criar a história 'Spike: validar HostGator compartilhada com Laravel 13' (P) sob NI2-1, antes da fundação, com o roteiro da seção 6.13 e decisão go/no-go.
- Voltar NI2-119, NI2-124 e NI2-130 para 'A fazer' e anotar que o código atual é simulação e será descartado.
- Reabrir NI2-113: o documento se declara 'Validado e Aprovado' sem entrevistas nem evidências exigidas no aceite.
- Corrigir a inconsistência de NI2-11 ('A fazer') com as filhas NI2-86 a NI2-91 ('Feito').
- Criar uma história de fundação 'Bootstrap Laravel 13 + CI + remote git' sob NI2-1, ou um épico de replataforma, como primeiro item do board.
- Revisar prioridades: hoje quase tudo está 'Medium'. Usar as fases da seção 8 para P0/P1.
- Adicionar a DoD da seção 8.2 como checklist padrão das histórias.

---

## 11. Quando sair da HostGator compartilhada

A saída é planejada desde já: o app não deve ter código específico da HostGator. Os limiares abaixo são **sugestões iniciais**, a calibrar com as métricas da fase 1.

| Gatilho | Sinal objetivo (sugestão) | Por que pesa |
|---|---|---|
| Tempo real | Chat (NI2-118/138) ou atendimento exigindo entrega imediata; teleconsulta própria | Websockets (Reverb) exigem processo contínuo e porta própria, proibidos no compartilhado. |
| Fila e agendamento | Atraso de até 15 min passa a ser inaceitável (lembrete no minuto exato, import grande); o worker do scheduler bate `--max-time` em mais de 20% das execuções; job mais antigo > 30 min de forma recorrente | Sem worker permanente nem cron < 15 min. |
| Limites da conta | Qualquer aviso de uso excessivo de CPU (25% por ≥ 90 s) ou suspensão; erros de `max_user_connections` (25) ou de limite de processos (25); p95 > 1 s com uso normal; inodes > 70% do plano | Risco de indisponibilidade e de suspensão sem aviso. |
| Banco de dados | Banco > ~5 GB, dump + restauração > 30 min, ou necessidade de recuperação ponto-a-ponto (binlog) ou réplica | Backup e restauração ficam lentos e frágeis; sem PITR no compartilhado. |
| Segurança e LGPD | Contrato ou cliente exige ambiente dedicado/isolado, pentest com controle de WAF, residência de dados no Brasil garantida, privilégios de banco por tabela/trigger para imutabilidade, ou a conta só tem MySQL 5.7/CentOS 7 | Controles indisponíveis em servidor compartilhado. |
| Escala do negócio | Mais de ~30 tenants ativos ou ~100 usuários simultâneos no pico; go-live do app do paciente com push/chat em escala | Polling do app consome conexões e processos compartilhados. |
| Integrações | Integração que exige processo contínuo (sync de saúde contínuo, fila de webhooks de alto volume) ou porta de entrada não-HTTP | Daemons proibidos; portas de entrada nunca abertas. |

**Alvo da fase 2:** VPS gerenciada (ex.: servidor provisionado via Laravel Forge/Ploi, ou VPS da própria HostGator) ou PaaS Laravel (ex.: Laravel Cloud; região disponível não verificada), com Nginx + PHP-FPM 8.3+, **MySQL 8 gerenciado em São Paulo** (ex.: AWS RDS sa-east-1 ou OCI São Paulo), Redis (fila, cache, sessão, locks), Supervisor com `queue:work` (ou Horizon), cron por minuto, Reverb para websockets, storage S3-compatível e backups gerenciados com PITR.

**Regras de portabilidade desde o dia 1** (a mudança vira troca de `.env` + infraestrutura):

- Toda escolha de infraestrutura passa por config/env: `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `MAIL_MAILER`, `FILESYSTEM_DISK`, `LOG_STACK`, `BROADCAST_CONNECTION`, `DB_*`.
- Nenhum caminho `/home/...`, nome de servidor ou recurso do cPanel no código. Só nos scripts de deploy e no cron, que são parametrizados.
- Arquivos sempre pela facade `Storage` (local → S3 sem mudança de código); jobs idempotentes e curtos, que rodam igual com worker permanente.
- Schedule definido em `routes/console.php`; na fase 2, só o cron passa de `*/15` para `* * * * *`.
- Polling e push continuam como fallback quando os websockets entrarem; a API não muda.
- Recursos exclusivos do MySQL 8 só com a versão confirmada; nada exclusivo da HostGator.
- Deploy por artefato (o mesmo pacote do CI serve à VPS).

**Esforço estimado da mudança:** P (~1 semana-dev) se as regras acima forem seguidas: provisionar, configurar envs, dump/restore do banco + sync do storage, ligar workers/Redis/Reverb, baixar o TTL do DNS antes e migrar numa janela de manutenção. Estimativa grosseira.


---

## 12. O que não foi verificado

- Comportamento em MySQL 8: `date('now')` em EngagementService.php:73, subquery sem alias em PracticeCrmService.php:85-91 e DDL em transação em Migrator.php. Não havia MySQL disponível; tudo rodou em SQLite.
- Itens da HostGator marcados 'A confirmar no painel' (seção 6.2): versão do MySQL da conta, extensões PHP (fileinfo/dom/intl/zip), OPcache, limites efetivos de memória e tempo (web e CLI), Composer global, symlinks, troca do docroot do domínio principal, Redis, limite de tamanho por banco, conexões do Turbo (25 × 50), Git no cPanel, `mysqldump`, ModSecurity com a API, efeito de `defer()` no handler PHP e localização do servidor. Roteiro na seção 6.13.
- Os limiares numéricos dos gatilhos de saída (seção 11) e as metas de RPO/RTO (seção 6.8) são sugestões para calibrar com métricas reais.
- Existência de dados reais no banco local (o `.env` e o `storage/` não vieram na cópia).
- Versão atual do SDK do Expo recomendada; não pesquisei, só registrei que o projeto usa ~51.
- Prazo legal de guarda do prontuário nutricional (precisa de validação jurídica/CFN).
- As estimativas P/M/G são ordem de grandeza, não compromisso.

---

## 13. Fontes (consultadas em 07/10/2026)

Fontes públicas, consultadas em 07/10/2026 (BRT). A HostGator avisa que versões e limites mudam sem aviso: reconferir no spike.

- **[H1]** [HostGator BR — Versões dos principais softwares](https://suporte.hostgator.com.br/hc/pt-br/articles/30820193707155)
- **[H2]** [HostGator BR — Quais são as compatibilidades da HostGator?](https://suporte.hostgator.com.br/hc/pt-br/articles/30811116692115)
- **[H3]** [HostGator BR — Quais são as limitações dos servidores da HostGator?](https://suporte.hostgator.com.br/hc/pt-br/articles/30816318898963)
- **[H4]** [HostGator BR — Qual é o limite de conexões simultâneas ao MySQL?](https://suporte.hostgator.com.br/hc/pt-br/articles/30819637323923)
- **[H5]** [HostGator BR — Como funcionam as configurações do PHP](https://suporte.hostgator.com.br/hc/pt-br/articles/30818470707219)
- **[H6]** [HostGator BR — Como alterar a versão do PHP na hospedagem](https://suporte.hostgator.com.br/hc/pt-br/articles/30807998885395)
- **[H7]** [HostGator BR — Como funciona um trabalho cron](https://suporte.hostgator.com.br/hc/pt-br/articles/30814729836435)
- **[H8]** [HostGator BR — Caminhos para execução de comandos em um Cron Job](https://suporte.hostgator.com.br/hc/pt-br/articles/30812660878867)
- **[H9]** [HostGator BR — Como habilitar o acesso SSH no plano de hospedagem](https://suporte.hostgator.com.br/hc/pt-br/articles/30814927583379)
- **[H10]** [HostGator BR — Como acessar um servidor via SSH (porta 2222)](https://suporte.hostgator.com.br/hc/pt-br/articles/30812824748051)
- **[H11]** [HostGator BR — Como instalar o Laravel](https://suporte.hostgator.com.br/hc/pt-br/articles/30814116483987)
- **[H12]** [HostGator BR — Como adicionar um domínio na hospedagem (Document Root)](https://suporte.hostgator.com.br/hc/pt-br/articles/30810955851027)
- **[H13]** [HostGator BR — Como funciona o certificado SSL gratuito](https://suporte.hostgator.com.br/hc/pt-br/articles/30808218548627)
- **[H14]** [HostGator BR — Quais são as regras para envio de e-mails?](https://suporte.hostgator.com.br/hc/pt-br/articles/30822248903827)
- **[H15]** [HostGator BR — Quais portas são liberadas por padrão](https://suporte.hostgator.com.br/hc/pt-br/articles/30812710494739)
- **[H16]** [HostGator BR — Como funciona a abertura de portas em servidores compartilhados](https://suporte.hostgator.com.br/hc/pt-br/articles/30817408700691)
- **[H17]** [HostGator BR — Plano Start: recursos e funcionalidades](https://suporte.hostgator.com.br/hc/pt-br/articles/30807105628819)
- **[H18]** [HostGator BR — Plano P: recursos e funcionalidades](https://suporte.hostgator.com.br/hc/pt-br/articles/30812597854995)
- **[H19]** [HostGator BR — Plano M: recursos e funcionalidades](https://suporte.hostgator.com.br/hc/pt-br/articles/30808550507027)
- **[H20]** [HostGator BR — Plano Turbo: recursos e funcionalidades](https://suporte.hostgator.com.br/hc/pt-br/articles/30816312886547)
- **[H21]** [HostGator BR — Como fazer um backup completo da hospedagem](https://suporte.hostgator.com.br/hc/pt-br/articles/30813484137491)
- **[H22]** [HostGator BR — Termos de serviço](https://www.hostgator.com.br/termos-de-servico)
- **[H23]** [HostGator BR — Especificações dos servidores](https://suporte.hostgator.com.br/hc/pt-br/articles/30818426910355)
- **[H24]** [HostGator BR (blog) — HostGator na Oracle Cloud (São Paulo)](https://www.hostgator.com.br/blog/hostgator-na-oracle-cloud-brasil/)
- **[H25]** [HostGator BR — Como corrigir o erro 500 (limite de memória padrão)](https://suporte.hostgator.com.br/hc/pt-br/articles/30810748316947)
- **[H26]** [HostGator EUA (não é a operação BR) — How to Set Up Composer](https://www.hostgator.com/help/article/how-to-set-up-composer)
- **[L1]** [Laravel 13 — Deployment (requisitos, optimize, /up)](https://laravel.com/docs/13.x/deployment)
- **[L2]** [Laravel 13 — Database (MySQL 5.7+ suportado)](https://laravel.com/docs/13.x/database)
- **[L3]** [Laravel 13 — Queues (--stop-when-empty, --max-time, --timeout)](https://laravel.com/docs/13.x/queues)
- **[L4]** [Laravel 13 — Task Scheduling (schedule:run, withoutOverlapping)](https://laravel.com/docs/13.x/scheduling)
- **[L5]** [Laravel 13 — Helpers (defer)](https://laravel.com/docs/13.x/helpers)
- **[L6]** [Laravel 13 — Mail (drivers Postmark/Resend/Mailgun/SES, failover)](https://laravel.com/docs/13.x/mail)
- **[L7]** [Laravel 13 — File Storage (storage:link, links)](https://laravel.com/docs/13.x/filesystem)
- **[M1]** [MySQL 8.0 — CHECK constraints (ignoradas antes da 8.0.16)](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html)
- **[M2]** [MySQL 8.0 — Data type defaults (default por expressão em JSON/TEXT)](https://dev.mysql.com/doc/refman/8.0/en/data-type-defaults.html)
- **[M3]** [MySQL 8.0 — Binary logging of stored programs (triggers/funções e SUPER)](https://dev.mysql.com/doc/refman/8.0/en/stored-programs-logging.html)
