#!/usr/bin/env bash
set -euo pipefail

OUT="/etc/asterisk/queues.generated.conf"
TMP="$(mktemp)"

# Pega os ramais do AstDB (taxi/nome/<ramal>) e ordena
RAMAIS="$(asterisk -rx "database show taxi/nome" \
  | awk -F'/' '/^\/taxi\/nome\// {
      ramal=$4
      sub(/[[:space:]].*$/, "", ramal)
      if (ramal ~ /^[0-9]+$/) print ramal
    }' \
  | sort -n)"

{
  echo "; ====================================================="
  echo "; AUTO-GERADO - NAO EDITAR MANUALMENTE"
  echo "; Arquivo: $OUT"
  echo "; Gerado em: $(date '+%Y-%m-%d %H:%M:%S')"
  echo "; ====================================================="
  echo
  echo "[uber_ringall]"
  echo "musicclass=default"
  echo "strategy=ringall"
  echo "timeout=20"
  echo "retry=1"
  echo "wrapuptime=0"
  echo "maxlen=0"
  echo "joinempty=no"
  echo "leavewhenempty=yes"
  echo "ringinuse=no"
  echo "setinterfacevar=yes"
  echo "setqueuevar=yes"
  echo
  # Members
  if [[ -n "${RAMAIS}" ]]; then
    while read -r R; do
      [[ -z "$R" ]] && continue
      echo "member => PJSIP/${R}"
    done <<< "${RAMAIS}"
  fi
} > "$TMP"

# Move atômico
mv "$TMP" "$OUT"
chown root:asterisk "$OUT"
chmod 664 "$OUT"

# Recarrega filas
asterisk -rx "queue reload all" >/dev/null

# Mostra resultado
echo "OK: gerado $OUT"
asterisk -rx "queue show uber_ringall"
