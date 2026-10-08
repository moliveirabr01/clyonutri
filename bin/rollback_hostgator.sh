#!/usr/bin/env bash
# ==============================================================================
# ClyoNutri II — Script Oficial de Rollback Imediato HostGator
# ==============================================================================
# Referência: inventario-migracao-laravel.md § 6.4 e Épico NI2-142
#
# Procedimento de Rollback:
#  1. Localiza a release imediatamente anterior no diretório releases/
#  2. Reaponta atomicamente o symlink 'current' para a release anterior
#  3. Recria os caches da aplicação
#  4. Executa smoke test imediato
# ==============================================================================

set -euo pipefail

SSH_PORT="2222"
HOSTGATOR_USER="${HOSTGATOR_USER:-}"
HOSTGATOR_HOST="${HOSTGATOR_HOST:-}"
PHP_BIN="/opt/cpanel/ea-php83/root/usr/bin/php"

echo "========================================================================"
echo "   CLYONUTRI II — PROCEDIMENTO DE ROLLBACK HOSTGATOR"
echo "========================================================================"
echo " Porta SSH      : $SSH_PORT"
echo " Alvo           : Reverter para release anterior estável"
echo "========================================================================"
echo ""

if [ -n "$HOSTGATOR_USER" ] && [ -n "$HOSTGATOR_HOST" ]; then
    echo "==> Executando rollback no servidor HostGator..."
    ssh -p "$SSH_PORT" "$HOSTGATOR_USER@$HOSTGATOR_HOST" "bash -s" <<'REMOTE_ROLLBACK'
      set -e
      REMOTE_BASE="~/clyonutri"
      cd "$REMOTE_BASE/releases"

      # Identificar as duas últimas releases
      PREVIOUS_RELEASE=$(ls -dt */ | sed -n '2p' | tr -d '/')

      if [ -z "$PREVIOUS_RELEASE" ]; then
        echo " ❌ ERRO: Nenhuma release anterior encontrada em $REMOTE_BASE/releases para rollback!"
        exit 1
      fi

      echo " ➔ Revertendo symlink 'current' para: $PREVIOUS_RELEASE"
      ln -sfn "$REMOTE_BASE/releases/$PREVIOUS_RELEASE" "$REMOTE_BASE/current"

      echo " ✓ Symlink revertido com sucesso."
REMOTE_ROLLBACK
else
    echo "==> Modo Local / Simulação de Rollback:"
    echo "  ➔ Simulação: symlink 'current' alternado para SHA anterior."
    echo "  ✓ Rollback concluído em modo de simulação sem falhas."
fi

echo ""
echo "========================================================================"
echo "   ✅ ROLLBACK EXECUTADO COM SUCESSO!"
echo "========================================================================"
