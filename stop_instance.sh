#!/bin/bash

ID=$1
echo "[STOP] Parando instância $ID via Master Server..."

# Usa API do Master Server para parar
curl -X POST "http://localhost:3001/api/instances/$ID/stop" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "[STOP] Instância parada com sucesso: $ID"
else
    echo "[STOP] Erro ao parar: $ID"
fi
