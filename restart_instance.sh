#!/bin/bash

ID=$1

# Função para parar o Master Server
stop_master() {
    echo "[MASTER] Parando Master Server..."
    if [ -f master-meta.pid ]; then
        PID=$(cat master-meta.pid)
        if ps -p $PID > /dev/null 2>&1; then
            kill $PID
            sleep 2
            echo "[MASTER] Master Server parado (PID: $PID)"
        else
            echo "[MASTER] PID $PID não está em execução"
        fi
    else
        echo "[MASTER] PID file não encontrado"
    fi
}

# Função para iniciar o Master Server
start_master() {
    echo "[MASTER] Iniciando Master Server..."
    nohup node master-server.js > master-server.out.log 2>&1 &
    sleep 3
    echo "[MASTER] Master Server iniciado"
}

# Função para obter lista de todas as instâncias
get_all_instances() {
    curl -s "http://localhost:3001/api/instances" 2>/dev/null | grep -o '"instanceId":"[^"]*"' | cut -d'"' -f4
}

if [ "$ID" = "all" ]; then
    echo "[RESTART] Modo: Reiniciar TODAS as instâncias + Master Server"
    
    # Para todas as instâncias
    echo "[INSTANCES] Listando instâncias..."
    INSTANCES=$(get_all_instances)
    
    if [ -z "$INSTANCES" ]; then
        echo "[INSTANCES] Nenhuma instância encontrada via API"
        # Fallback: tentar via processo
        INSTANCES=$(ps aux | grep "instance_inst_" | grep -v grep | awk '{for(i=1;i<=NF;i++) if($i~/instance_inst_/) print $i}' | sed 's/.*instance_//' | sed 's/\.log.*//' | sort -u)
    fi
    
    echo "[INSTANCES] Instâncias encontradas: $INSTANCES"
    
    # Para cada instância
    for INSTANCE in $INSTANCES; do
        echo "[INSTANCES] Parando instância $INSTANCE..."
        curl -X POST "http://localhost:3001/api/instances/$INSTANCE/stop" 2>/dev/null
        sleep 1
    done
    
    echo "[INSTANCES] Todas as instâncias paradas"
    
    # Para o Master Server
    stop_master
    
    echo "[RESTART] Aguardando 3 segundos..."
    sleep 3
    
    # Inicia o Master Server
    start_master
    
    echo "[RESTART] Aguardando Master Server estar pronto..."
    sleep 5
    
    # Reinicia todas as instâncias
    echo "[INSTANCES] Reiniciando todas as instâncias..."
    for INSTANCE in $INSTANCES; do
        echo "[INSTANCES] Reiniciando instância $INSTANCE..."
        curl -X POST "http://localhost:3001/api/instances/$INSTANCE/restart" 2>/dev/null
        sleep 2
    done
    
    echo "[RESTART] ✓ Todas as instâncias + Master Server reiniciados"
else
    echo "[RESTART] Reiniciando instância $ID via Master Server..."
    
    # Usa API do Master Server para reiniciar (mata e recria o processo)
    curl -X POST "http://localhost:3001/api/instances/$ID/restart" 2>/dev/null
    
    if [ $? -eq 0 ]; then
        echo "[RESTART] Reiniciada com sucesso: $ID"
    else
        echo "[RESTART] Erro ao reiniciar: $ID"
    fi
fi
