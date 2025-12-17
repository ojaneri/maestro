#!/bin/bash

ID=$1
PORT=$2
BASE="/var/www/html/janeri.com.br/api/envio/wpp"

echo "[CREATE] Criando instância $ID na porta $PORT"

pm2 start $BASE/whatsapp-server-intelligent.js --name "wpp_$ID" -- \
    --id="$ID" \
    --port="$PORT"

echo "[CREATE] Instância $ID criada."
