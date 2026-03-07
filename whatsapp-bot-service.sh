#!/bin/bash

# whatsapp-bot-service.sh - Gestão de Processos e Limpeza de Segurança

ACTION=$1

log() {
    echo "[$(date +'%Y-%m-%dT%H:%M:%S')] $1"
}

cleanup() {
    log "Iniciando limpeza profunda de processos fantasmas..."
    
    # 1. Mata processos PM2 legados que costumam sequestrar portas
    log "Limpando PM2..."
    pm2 delete all 2>/dev/null
    pm2 kill 2>/dev/null
    
    # 2. Mata todos os workers node (que usam --id=inst_)
    log "Finalizando workers do WhatsApp..."
    ps aux | grep "node" | grep "--id=inst_" | grep -v grep | awk '{print $2}' | xargs -r kill -9
    
    # 3. Mata o Master Server
    log "Finalizando Master Server..."
    pkill -f "master-server.js"
    
    # 4. Limpa as portas TCP conhecidas (3001, 3010-3020)
    log "Liberando portas TCP..."
    fuser -k 3001/tcp 2>/dev/null
    for port in {3010..3020}; do
        fuser -k $port/tcp 2>/dev/null
    done
    
    log "Limpeza concluída."
}

start() {
    log "Iniciando Master Server..."
    nohup node master-server.js > master-server.out.log 2>&1 &
    log "Master Server iniciado em background."
}

case "$ACTION" in
    "cleanup")
        cleanup
        ;;
    "restart")
        cleanup
        sleep 2
        start
        ;;
    "start")
        start
        ;;
    *)
        echo "Uso: $0 {cleanup|restart|start}"
        exit 1
        ;;
esac
