#!/usr/bin/env bash
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)/asterisk"
DST_DIR="/etc/asterisk"
BK_DIR="/root/asterisk-backups/$(date +%F_%H%M%S)"

echo "[1/4] Criando backup em: $BK_DIR"
sudo mkdir -p "$BK_DIR"
sudo cp -a "$DST_DIR"/*.conf "$BK_DIR"/

echo "[2/4] Copiando configs do repo -> /etc/asterisk"
sudo cp -a "$SRC_DIR"/*.conf "$DST_DIR"/

echo "[3/4] Ajustando permissões"
sudo chown root:root "$DST_DIR"/*.conf
sudo chmod 644 "$DST_DIR"/*.conf

echo "[4/4] Recarregando Asterisk"
sudo asterisk -rx "dialplan reload" || true
sudo asterisk -rx "pjsip reload" || true
sudo asterisk -rx "queue reload all" || true

echo "OK ✅ Deploy concluído."
