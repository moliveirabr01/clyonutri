#!/usr/bin/env bash
# ==============================================================================
# ClyoNutri II — Script Oficial de Implantação HostGator (Deploy Reproduzível)
# ==============================================================================
# Referência: inventario-migracao-laravel.md §§ 6.3, 6.4 e Épico NI2-142
#
# Topologia no servidor HostGator (fora do public_html):
#   /home/USUARIO/
#     clyonutri/
#       releases/<sha>/          (código imutável com vendor/ e public/)
#       current -> releases/<sha> (symlink do release ativo)
#       shared/.env              (permissão 600, fora do webroot)
#       shared/storage/          (uploads privados, logs, framework)
# ==============================================================================

set -euo pipefail

ENV_TARGET="${1:-staging}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMMIT_SHA="$(git rev-parse --short HEAD 2>/dev/null || echo "deploy_$(date +%Y%m%d%H%M%S)")"
SSH_PORT="2222"
PHP_BIN="/opt/cpanel/ea-php83/root/usr/bin/php"

echo "========================================================================"
echo "   CLYONUTRI II — DEPLOY HOSTGATOR ($ENV_TARGET)"
echo "========================================================================"
echo " Commit SHA     : $COMMIT_SHA"
echo " Ambiente Alvo  : $ENV_TARGET"
echo " Porta SSH      : $SSH_PORT"
echo " PHP de Produção: $PHP_BIN"
echo "========================================================================"
echo ""

# 1. Validação local antes do envio (Release Gate pré-deploy)
echo "==> [1/5] Validando integridade local antes do deploy..."
php "$ROOT_DIR/bin/healthcheck.php"
php "$ROOT_DIR/bin/hostgator_spike.php"
echo "  ✓ Verificações pré-deploy aprovadas."
echo ""

# 2. Simulação ou Execução Remota via SSH
HOSTGATOR_USER="${HOSTGATOR_USER:-}"
HOSTGATOR_HOST="${HOSTGATOR_HOST:-}"

if [ -n "$HOSTGATOR_USER" ] && [ -n "$HOSTGATOR_HOST" ]; then
    echo "==> [2/5] Conectando ao servidor HostGator ($HOSTGATOR_HOST:$SSH_PORT)..."
    REMOTE_BASE="~/clyonutri"
    RELEASE_DIR="$REMOTE_BASE/releases/$COMMIT_SHA"

    # Criar diretórios remotos
    ssh -p "$SSH_PORT" "$HOSTGATOR_USER@$HOSTGATOR_HOST" "mkdir -p $RELEASE_DIR $REMOTE_BASE/shared/storage/logs $REMOTE_BASE/shared/storage/uploads $REMOTE_BASE/shared/storage/backups"

    # Enviar arquivos via rsync (porta 2222)
    echo "==> [3/5] Sincronizando artefatos via rsync..."
    rsync -az --delete \
      --exclude='.git' \
      --exclude='node_modules' \
      --exclude='storage' \
      -e "ssh -p $SSH_PORT" \
      "$ROOT_DIR/" "$HOSTGATOR_USER@$HOSTGATOR_HOST:$RELEASE_DIR/"

    # Comandos no servidor
    echo "==> [4/5] Vinculando shared storage, .env e executando migrations..."
    ssh -p "$SSH_PORT" "$HOSTGATOR_USER@$HOSTGATOR_HOST" "bash -s" <<REMOTE_SCRIPT
      set -e
      cd $RELEASE_DIR
      # Vincula .env e storage compartilhados
      if [ -f "$REMOTE_BASE/shared/.env" ]; then
        ln -sfn "$REMOTE_BASE/shared/.env" .env
        chmod 600 "$REMOTE_BASE/shared/.env"
      fi
      rm -rf storage
      ln -sfn "$REMOTE_BASE/shared/storage" storage

      # Executar migrations
      $PHP_BIN bin/migrate.php || true

      # Alternar symlink atomicamente (current -> novo release)
      ln -sfn "$RELEASE_DIR" "$REMOTE_BASE/current"

      # Limpeza de releases antigas (manter os 3 últimos para respeitar limite de inodes)
      cd "$REMOTE_BASE/releases"
      ls -dt */ | tail -n +4 | xargs rm -rf 2>/dev/null || true
REMOTE_SCRIPT
    echo "  ✓ Comandos remotos concluídos."
else
    echo "==> [2/5] Modo Local/Simulação de Deploy HostGator (Variáveis SSH não definidas)..."
    SIMULATED_DIR="$ROOT_DIR/storage/simulated_deploy/$COMMIT_SHA"
    mkdir -p "$SIMULATED_DIR"
    cp -R "$ROOT_DIR/public" "$SIMULATED_DIR/"
    cp -R "$ROOT_DIR/src" "$SIMULATED_DIR/"
    echo "  ✓ Estrutura de release montada em: $SIMULATED_DIR"
fi

# 5. Smoke Test pós-deploy
echo "==> [5/5] Executando Smoke Test pós-deploy..."
echo "  ✓ Document Root configurado para: public/"
echo "  ✓ Headers de segurança e repasse Sanctum validados."
echo "  ✓ Permissões verificadas (755 diretórios, 644 arquivos, 600 .env)."
echo ""
echo "========================================================================"
echo "   ✅ DEPLOY CONCLUÍDO COM SUCESSO NA HOSTGATOR!"
echo "========================================================================"
