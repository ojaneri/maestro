#!/bin/bash

ID=$1
echo "[STOP] Parando instância $ID"
pm2 stop "wpp_$ID"
pm2 delete "wpp_$ID"

