# ClyoNutri II — Decisão Formal GO / NO-GO de Infraestrutura HostGator

**Data da Auditoria:** 07/10/2026  
**Auditor Responsável:** Equipe de Engenharia ClyoNutri II  
**Aprovador:** Maicon Oliveira (Product Owner)  
**Referência Jira:** Épico NI2-142 e História NI2-147  
**Base:** `inventario-migracao-laravel.md` §§ 6.2 e 6.13  

---

## 1. Parecer Executivo

**DECISÃO: GO CONDICIONADO A SALVAGUARDAS OPERACIONAIS**

A hospedagem compartilhada da HostGator Brasil (Planos M ou Turbo) **atende** aos requisitos da Fase 1 do ClyoNutri II (monólito modular Laravel 13, Blade, Fortify, API Sanctum para o aplicativo do paciente e banco de dados relacional), desde que cumpridas integralmente as 5 premissas obrigatórias:

1. **Conta alocada em servidor AlmaLinux com MySQL 8.0** (confirmada por escrito junto ao suporte da HostGator; rejeitar servidores legados CentOS 7 com MySQL 5.7).
2. **Arquitetura estritamente orientada a lote para o agendador e fila:** cron de 15 minutos (`*/15 * * * *`) e ausência total de daemons/workers contínuos.
3. **E-mails críticos enviados sincronamente** via provedor transacional externo (API HTTPS), sem depender da fila de 15 minutos.
4. **Comunicação mobile/chat por polling inteligente + notificações push**, sem dependência de WebSockets na fase inicial.
5. **Backups próprios, cifrados e externos** com ensaios regulares de restauração, sem confiar no backup do cPanel como plano de disaster recovery.

---

## 2. Matriz de Avaliação dos 15 Critérios Técnicos

| # | Item Avaliado | Requisito ClyoNutri II | Situação HostGator Compartilhada | Status | Mitigação Obrigatória |
|---|---|---|---|:---:|---|
| 1 | **Versão do PHP** | PHP >= 8.3 | PHP 8.3 disponível via MultiPHP e CLI `/opt/cpanel/ea-php83/root/usr/bin/php` | **GO** | Fixar PHP 8.3 no domínio, subdomínios, `.bashrc` e cron jobs. |
| 2 | **Extensões PHP** | `fileinfo`, `dom`, `intl`, `zip`, `pdo_mysql`, `mbstring` | Disponíveis no cPanel (algumas necessitam ativação no painel de extensões) | **GO** | Executar `php bin/hostgator_spike.php` antes de qualquer deploy. |
| 3 | **OPcache** | Otimização de bytecode | Disponível nos servidores modernos AlmaLinux | **GO** | Ajustar `opcache.enable=1` e validar ganho de latência. |
| 4 | **Limites Web (Timeout/RAM)** | Memory 512M, Timeout <= 60s | Máximo documentado 512M e 60s | **GO** | Operações pesadas (importação de pacientes/relatórios) processadas em lotes curtos. |
| 5 | **Versão do Banco de Dados** | MySQL 8.0 | AlmaLinux usa 8.0; CentOS 7 usa 5.7 | **GO CONDICIONADO** | Exigir servidor AlmaLinux com MySQL 8.0 antes do provisionamento. |
| 6 | **Conexões MySQL** | Suportar tráfego inicial | Máximo de 25 conexões simultâneas | **GO** | Desligar conexões persistentes; monitorar `max_user_connections`. |
| 7 | **Document Root** | Webroot estritamente `public/` | Editável em Domínios Adicionais e Subdomínios | **GO** | Subdomínio dedicado (`app.clyonutri.com.br`) apontando para `~/clyonutri/current/public`. |
| 8 | **Symlinks (Links Simbólicos)** | Deploy atômico (`current`) | Suportado no sistema de arquivos | **GO** | Script `bin/deploy_hostgator.sh` com fallback automático se o Apache não seguir links. |
| 9 | **Compliance de Daemons** | Sem processos contínuos | Proibição de daemons pelo TOS §9.3 | **GO** | Fila `database` drenada via `--stop-when-empty` no cron; sem Horizon/Supervisor. |
| 10 | **Intervalo de Cron** | Agendamento previsível | Mínimo de 15 minutos (`*/15`) | **GO** | Granularidade de 15 min aceita para rotinas não críticas. |
| 11 | **E-mails Transacionais** | Garantia de entrega | Limite HostGator dinâmico e restrito | **GO** | Integração direta com API HTTPS de provedor transacional (Resend/Postmark/SES). |
| 12 | **Uploads Clínicos** | Sigilo e LGPD | Armazenamento privado | **GO** | Uploads em `~/clyonutri/shared/storage/app/private/` servidos por controllers com autenticação. |
| 13 | **Estratégia de Backup** | Imutabilidade e recuperação | HostGator sobrescreve backups | **GO** | Rotina própria diária (`bin/backup_database.sh`) cifrada com AES-256 e cópia externa. |
| 14 | **Headers da API (Sanctum)** | Repasse de `Authorization` | Requer diretiva mod_rewrite | **GO** | Diretiva configurada e testada no `public/.htaccess`. |
| 15 | **Plano Mínimo** | Inodes e recursos | Plano M ou Turbo | **GO** | Plano M (250 mil inodes) ou Turbo (500 mil inodes). Plano Start rejeitado. |

---

## 3. Conclusão e Próximos Passos

1. Aprovado o uso da HostGator compartilhada para as fases de Fundação, Homologação e Entrada em Operação (Fases 1 a 6).
2. O script automatizado `bin/hostgator_spike.php` passa a integrar os gates obrigatórios de CI/CD e release.
3. As condições de saída para VPS/PaaS gerenciada estão formalmente documentadas no Runbook e ativáveis sob demanda.
