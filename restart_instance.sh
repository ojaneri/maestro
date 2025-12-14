#!/bin/bash

ID=$1
echo "[RESTART] Reiniciando instância $ID"
pm2 restart "wpp_$ID"

