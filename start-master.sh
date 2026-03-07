#!/bin/bash
#
# start-master.sh - Start the Master Server
# Run as www-data user

cd /var/www/html/maestro.janeri.com.br

# Check if already running
if pgrep -f "master-server.js" > /dev/null; then
    echo "Master server is already running"
    exit 0
fi

echo "Starting Master Server..."
node master-server.js > master-server.out.log 2>&1 &

echo "Master server started with PID: $!"
echo "Logs: master-server.log"
echo "Output: master-server.out.log"
