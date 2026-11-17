# Reconciliação v54.71 → v54.75

Codebase canônica preservada. Pacote v54.75 substitui v54.74.

Recuperados: login administrativo e sessões sem clínica; política MFA_PLATFORM_REQUIRED e step-up de sessão; tela e confirmação única de recuperação MFA; leitura cifrada em prontuários, alertas/snapshots, anamnese, onboarding e LGPD; transporte OTP pelo MailerFactory com fallback portal; repetição idempotente de rascunhos; guardas CLI/.env no script de cifragem; SQLs históricos como referência.

Mantidos: alterações recentes de agenda, planos, diário alimentar, assinatura, prescrições, linguagem, telemetria, reimportação e otimizações. Os caminhos omitidos da v54.71 são ferramentas de desenvolvimento e instruções antigas; lista exata em comparison.json. Não foi substituída a árvore pelo pacote histórico.

Validação:
- Core: 24 suítes, zero falhas (build.log).
- Reconciliação: 17 verificações, zero falhas (reconciliation.log).
- Runtime entregue: autenticação real via PHP-CGI, administrador/profissional, sessão sem clínica, isolamento de permissões, MFA/TOTP, oito códigos, CSRF e confirmação/auditoria; seis cenários compostos aprovados (auth-cgi.log).
- Runtime entregue: login e nove alterações persistidas de status de agenda aprovados (agenda-cgi.log).
- Manifesto consistente: v54.75-3f5e543634fc6318, 1695 arquivos.
- CRC, caminhos, dependências de produção e exclusão de dados/segredos locais conferidos.
- PHP nos arquivos reconciliados, node --check e git diff --check aprovados.

Limites: MySQL real, Apache HostGator, navegador e entrega SMTP externa NOT TESTED. Transporte OTP exercitado com driver log isolado e falha de configuração; sem alegação de entrega externa. Nenhum deploy realizado.

Baseline: ZIP v54.71 local. O download do anexo enviado falhou; embora seu tamanho coincida, sua identidade byte a byte com o ZIP local não foi confirmada. A reconciliação usa o conteúdo histórico efetivamente disponível, identificado por hash em comparison.json.
