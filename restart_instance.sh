#!/bin/bash

ID=$1
echo "[RESTART] Reiniciando instância $ID via Master Server..."

# Usa API do Master Server para reiniciar (mata e recria o processo)
curl -X POST "http://localhost:3001/api/instances/$ID/restart" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "[RESTART] Reiniciada com sucesso: $ID"
else
    echo "[RESTART] Erro ao reiniciar: $ID"
fi
