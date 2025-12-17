#!/bin/bash
#
# whatsapp-bot-service.sh v2.1
# Orquestrador de instâncias WhatsApp / Evolution API
# - AÇÕES de runtime: rodar como www-data
# - INSTALAÇÃO do serviço systemd: rodar como root

BASEDIR="$(cd "$(dirname "$0")" && pwd)"
NODE_SERVER="$BASEDIR/whatsapp-server-intelligent.js"
SERVICE_NAME="whatsapp-bot-orchestrator"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

ACTION="$1"
TARGET="$2"

# -------------------------------
# Regras de usuário (root x www-data)
# -------------------------------
if [[ "$ACTION" == "--install-service" || "$ACTION" == "--remove-service" ]]; then
    # instalar/remover serviço: precisa ser root
    if [ "$(id -u)" -ne 0 ]; then
        echo "[ERRO] --install-service e --remove-service devem ser executados como root."
        echo "Use: sudo $0 $ACTION"
        exit 1
    fi
else
    # demais ações: precisam ser www-data
    if [ "$(id -u)" -ne 33 ]; then
        echo "[ERRO] Este comando deve ser executado como www-data."
        echo "Use: sudo -u www-data $0 $ACTION $TARGET"
        exit 1
    fi
fi

# -------------------------------
# Detectar PM2 (para www-data)
# -------------------------------
PM2_BIN="$(command -v pm2 2>/dev/null)"
if [ -z "$PM2_BIN" ]; then
    [ -x "/usr/local/bin/pm2" ] && PM2_BIN="/usr/local/bin/pm2"
    [ -x "/usr/bin/pm2" ]       && PM2_BIN="/usr/bin/pm2"
fi

check_dependencies() {
    command -v node >/dev/null 2>&1 || echo "[WARN] node não encontrado no PATH"
    command -v php  >/dev/null 2>&1 || echo "[WARN] php não encontrado no PATH"
    if [ -z "$PM2_BIN" ]; then
        echo "[WARN] pm2 não encontrado para usuário $(whoami)"
    fi
    [ -f "$NODE_SERVER" ]    || echo "[WARN] whatsapp-server-intelligent.js não encontrado em $NODE_SERVER"
}

list_instances() {
    php -r '
        $base = "'"$BASEDIR"'";
        require $base . "/vendor/autoload.php";
        require $base . "/instance_data.php";
        $instances = loadInstancesFromDatabase();
        foreach ($instances as $id => $inst) {
            $name = $inst["name"] ?? $id;
            $port = $inst["port"] ?? 0;
            $status = $inst["connection_status"] ?? ($inst["status"] ?? "unknown");
            echo $id."|".$name."|".$port."|".$status.PHP_EOL;
        }
    '
}

pm2_status() {
    local name="wpp_$1"
    [ -z "$PM2_BIN" ] && { echo "unknown"; return; }
    local pid
    pid=$("$PM2_BIN" pid "$name" 2>/dev/null)
    [ -z "$pid" ] || [ "$pid" = "0" ] && echo "stopped" || echo "online"
}

start_instance() {
    local id="$1"
    local port="$2"
    local pm2_name="wpp_${id}"

    if [ -z "$PM2_BIN" ]; then
        echo "[ERRO] pm2 indisponível."
        return
    fi
    if [ "$(pm2_status "$id")" = "online" ]; then
        echo "[OK] $id já ONLINE."
        return
    fi
    echo "[START] Iniciando $id (porta $port)..."
    "$PM2_BIN" start "$NODE_SERVER" --name "$pm2_name" -- --id="$id" --port="$port" >/dev/null 2>&1 \
        && echo "[OK] $id iniciada" \
        || echo "[ERRO] Falha ao iniciar $id."
}

stop_instance() {
    local id="$1"
    local pm2_name="wpp_$id"

    if [ -z "$PM2_BIN" ]; then
        echo "[ERRO] pm2 indisponível."
        return
    fi
    if [ "$(pm2_status "$id")" != "online" ]; then
        echo "[INFO] $id já parada."
        return
    fi
    echo "[STOP] Parando $id..."
    "$PM2_BIN" delete "$pm2_name" >/dev/null 2>&1 && echo "[OK] $id parada"
}

show_status() {
    echo "ID           | Nome                | Porta | Status | PM2"
    echo "-------------+---------------------+-------+--------+--------"
    while IFS='|' read -r id name port jstatus; do
        [ -z "$id" ] && continue
        printf "%-11s | %-19s | %-5s | %-6s | %-8s\n" \
            "$id" "$name" "$port" "$jstatus" "$(pm2_status "$id")"
    done < <(list_instances)
}

heal_instances() {
    echo "[HEAL] Verificando instâncias..."
    while IFS='|' read -r id name port jstatus; do
        [ -z "$id" ] && continue
        if [ "$(pm2_status "$id")" != "online" ]; then
            echo "[HEAL] $id ($name) parada → iniciando..."
            start_instance "$id" "$port"
        else
            echo "[OK] $id ($name) já está ONLINE"
        fi
    done < <(list_instances)
    cleanup_removed_instances
}

cleanup_removed_instances() {
    if [ -z "$PM2_BIN" ]; then
        return
    fi

    mapfile -t desired_ids < <(list_instances | cut -d'|' -f1)
    export DESIRED_IDS="$(printf "%s\n" "${desired_ids[@]}")"

    python3 - "$PM2_BIN" <<'PY'
import json
import os
import subprocess
import sys

pm2_bin = sys.argv[1]
desired = set(filter(None, os.environ.get('DESIRED_IDS', '').split()))

try:
    raw = subprocess.check_output([pm2_bin, 'jlist'], stderr=subprocess.DEVNULL)
except (subprocess.CalledProcessError, FileNotFoundError):
    sys.exit(0)

try:
    processes = json.loads(raw)
except json.JSONDecodeError:
    sys.exit(0)

    for proc in processes:
        name = proc.get('name', '')
        if not name.startswith('wpp_'):
            continue
        instance_id = name.split('wpp_', 1)[1]
        if instance_id and instance_id not in desired:
            print(f"[HEAL] {instance_id} removida do registro → encerrando {name}")
            subprocess.run([pm2_bin, 'delete', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
PY
}

install_service() {
    echo "[INSTALL] Criando serviço systemd em $SERVICE_FILE ..."
    cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=WhatsApp Bot Orchestrator (Evolution API)
After=network.target

[Service]
User=www-data
WorkingDirectory=$BASEDIR
ExecStart=/usr/bin/bash $BASEDIR/whatsapp-bot-service.sh --heal
Restart=always
Environment=PATH=/usr/bin:/usr/local/bin

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable "$SERVICE_NAME"
    systemctl restart "$SERVICE_NAME"
    echo "[OK] Serviço $SERVICE_NAME instalado e iniciado."
}

remove_service() {
    echo "[REMOVE] Removendo serviço systemd..."
    systemctl stop "$SERVICE_NAME" 2>/dev/null || true
    systemctl disable "$SERVICE_NAME" 2>/dev/null || true
    rm -f "$SERVICE_FILE"
    systemctl daemon-reload
    echo "[OK] Serviço $SERVICE_NAME removido."
}

usage() {
    cat <<EOF
Uso:
  Como root:
    $0 --install-service
    $0 --remove-service

  Como www-data:
    sudo -u www-data $0 --status
    sudo -u www-data $0 --heal
    sudo -u www-data $0 --start ID|all
    sudo -u www-data $0 --stop  ID|all
    sudo -u www-data $0 --restart ID|all
EOF
}

# -------------------------------
# Execução principal
# -------------------------------
check_dependencies

case "$ACTION" in
    --install-service)
        install_service
        ;;
    --remove-service)
        remove_service
        ;;
    --status)
        show_status
        ;;
    --heal)
        heal_instances
        ;;
    --start)
        if [ "$TARGET" = "all" ]; then
            while IFS='|' read -r id name port jstatus; do
                [ -z "$id" ] && continue
                start_instance "$id" "$port"
            done < <(list_instances)
        else
            while IFS='|' read -r id name port jstatus; do
                [ "$id" = "$TARGET" ] || continue
                start_instance "$id" "$port"
            done < <(list_instances)
        fi
        ;;
    --stop)
        if [ "$TARGET" = "all" ]; then
            while IFS='|' read -r id name port jstatus; do
                [ -z "$id" ] && continue
                stop_instance "$id"
            done < <(list_instances)
        else
            stop_instance "$TARGET"
        fi
        ;;
    --restart)
        if [ "$TARGET" = "all" ]; then
            while IFS='|' read -r id name port jstatus; do
                [ -z "$id" ] && continue
                stop_instance "$id"
                start_instance "$id" "$port"
            done < <(list_instances)
        else
            while IFS='|' read -r id name port jstatus; do
                [ "$id" = "$TARGET" ] || continue
                stop_instance "$id"
                start_instance "$id" "$port"
            done < <(list_instances)
        fi
        ;;
    *)
        usage
        ;;
esac

exit 0
