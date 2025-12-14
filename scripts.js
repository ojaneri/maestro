console.log('scripts.js loaded successfully');

function toggleDetails(id) {
    const el = document.getElementById("details-" + id);
    el.style.display = el.style.display === "block" ? "none" : "block";
}

function logout() {
    fetch("logout.php")
        .then(() => window.location.reload());
}

let currentInstanceId = null;
let currentApiKey = null;
// QR-related variables with proper scope encapsulation
const QRManager = (function() {
    let currentQRInstanceId = null;
    let qrRequestController = null;
    
    return {
        setCurrentQRInstance: function(id) {
            currentQRInstanceId = id;
            console.log('QR Manager: Current instance set to', id);
        },
        
        getCurrentQRInstance: function() {
            return currentQRInstanceId;
        },
        
        abortCurrentRequest: function() {
            if (qrRequestController) {
                qrRequestController.abort();
                qrRequestController = null;
                console.log('QR Manager: Aborted current request');
            }
        },
        
        createRequestController: function() {
            this.abortCurrentRequest();
            qrRequestController = new AbortController();
            return qrRequestController;
        }
    };
})();

function openTestModal(instanceId, apiKey, name) {
    currentInstanceId = instanceId;
    currentApiKey = apiKey;
    document.getElementById('testModal').style.display = 'block';
    document.getElementById('testModalTitle').textContent = 'Send test message for: ' + name;
    document.getElementById('testForm').reset();
    document.getElementById('feedback').innerHTML = '';
}

function closeTestModal() {
    document.getElementById('testModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize connection statuses for all instances
    initializeConnectionStatuses();
    
    const form = document.getElementById('testForm');
    if (!form) {
        // Página atual não tem o modal de teste; não faz nada
        return;
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const phone = document.getElementById('testPhone').value;
        const message = document.getElementById('testMessage').value;
        const feedback = document.getElementById('feedback');

        feedback.className = '';
        feedback.innerHTML = 'Enviando...';

        // Send using the new API format with 'to' parameter
        fetch('api.php', {
            method: 'POST',
            headers: {
                'x-api-key': currentApiKey,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                to: phone,
                message: message
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                feedback.className = 'error';
                feedback.innerHTML = 'Erro: ' + data.error;
            } else {
                feedback.className = 'success';
                feedback.innerHTML = 'Mensagem enviada com sucesso! Resposta: ' + JSON.stringify(data.result, null, 2);
                form.reset();
            }
        })
        .catch(error => {
            feedback.className = 'error';
            feedback.innerHTML = 'Erro: ' + error.message;
        });
    });
});

// Debugging design issues with proper scope
(function() {
    console.log('Debugging design issues:');
    
    // Check CSS feature support safely
    const backdropSupport = CSS.supports && CSS.supports('backdrop-filter', 'blur(10px)');
    console.log('Backdrop-filter support:', backdropSupport);
    
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        try {
            const sidebarStyles = window.getComputedStyle(sidebar);
            console.log('Sidebar backdrop-filter applied:', sidebarStyles.backdropFilter || 'none');
        } catch (styleError) {
            console.warn('Could not get sidebar styles:', styleError.message);
        }
    }
    
    try {
        const bodyStyles = window.getComputedStyle(document.body);
        console.log('Body background applied:', bodyStyles.background || 'default');
    } catch (bodyStyleError) {
        console.warn('Could not get body styles:', bodyStyleError.message);
    }
})();

function selectInstance(id) {
    console.log('selectInstance called with id:', id);
    // Remove active class from all instance items
    document.querySelectorAll('.instance-item').forEach(item => item.classList.remove('active'));
    // Add active class to the selected instance item
    const selectedItem = document.querySelector(`.instance-item[data-id="${id}"]`);
    if (!selectedItem) {
        console.error('Instance item not found for id:', id);
        return;
    }
    selectedItem.classList.add('active');

    const inst = instances[id];
    if (!inst) {
        console.error('Instance data not found for id:', id);
        return;
    }
    
    document.getElementById('instance-title').textContent = inst.name;
    
    // Show initial loading state
    document.getElementById('instance-details').innerHTML = `
        <div class="instance-card">
            <p><strong>ID:</strong> <code>${inst.id}</code></p>
            <p><strong>Name:</strong> ${inst.name}</p>
            <p><strong>Port:</strong> ${inst.port}</p>
            <p><strong>Loading status...</strong></p>
            <p><strong>API Key:</strong> <span>${inst.api_key}</span> <button onclick="copyToClipboard('${inst.api_key}')">Copy</button></p>
            <div class="actions">
                <button onclick="openTestModal('${inst.id}', '${inst.api_key}', '${inst.name}')"><i class="fas fa-paper-plane"></i> Send Test Message</button>
                <a class="danger" href="?delete=${inst.id}"><i class="fas fa-trash"></i> Delete</a>
            </div>
        </div>
    `;
    
    document.getElementById('create-section').classList.add('hidden');
    document.getElementById('instance-section').classList.remove('hidden');
    
    // Get current status via proxy
    checkConnectionStatus(id).then(status => {
        const connectionStatus = status || 'Unknown';
        const runningStatus = window.statuses[id] || 'Unknown';
        
        // Create connection status with QR icon for disconnected instances
        let connectionStatusHtml = `<span class="status status-${connectionStatus.toLowerCase()}">${connectionStatus}</span>`;
        if (connectionStatus === 'Disconnected' || connectionStatus === 'Failed') {
            connectionStatusHtml += ` <i class="fas fa-qrcode qr-icon" onclick="requestQR('${id}')" title="Generate QR Code" style="cursor: pointer; color: var(--accent); margin-left: 8px; transition: all 0.3s ease;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'"></i>`;
        }
        
        document.getElementById('instance-details').innerHTML = `
            <div class="instance-card">
                <p><strong>ID:</strong> <code>${inst.id}</code></p>
                <p><strong>Name:</strong> ${inst.name}</p>
                <p><strong>Port:</strong> ${inst.port}</p>
                <div class="dual-status">
                    <p><strong>Server Status:</strong> <span class="status status-${runningStatus.toLowerCase()}">● ${runningStatus}</span></p>
                    <p><strong>WhatsApp Status:</strong> ${connectionStatusHtml}</p>
                </div>
                <p><strong>API Key:</strong> <span>${inst.api_key}</span> <button onclick="copyToClipboard('${inst.api_key}')">Copy</button></p>
                <p><strong>QR Proxy Endpoint:</strong></p>
                <pre>curl -X GET "https://janeri.com.br/api/envio/wpp/qr-proxy.php?id=${inst.id}"</pre>
                <p><strong>External Access (API):</strong></p>
                <pre>curl -X POST "https://janeri.com.br/api/envio/wpp/api.php" \
   -H "x-api-key: ${inst.api_key}" \
   -H "Content-Type: application/json" \
   -d '{
         "to": "558586030781",
         "message": "Test message"
       }'</pre>
                <p><strong>Internal Access (direct port):</strong></p>
                <pre>curl -X POST "http://localhost:${inst.port}/send-Message" \
   -H "Content-Type: application/json" \
   -d '{
         "to": "558586030781",
         "message": "Test message"
       }'</pre>
                <div class="openai-section">
                    <h3>OpenAI Integration</h3>
                    <form id="openai-form-${inst.id}" class="openai-form">
                        <label>
                            <input type="checkbox" id="openai-enabled-${inst.id}" ${inst.openai?.enabled ? 'checked' : ''}> Enable OpenAI Responses
                        </label>
                        <label>
                            API Key: <input type="password" id="openai-api-key-${inst.id}" value="${inst.openai?.api_key || ''}" placeholder="sk-...">
                        </label>
                        <label>
                            System Prompt: <textarea id="openai-system-prompt-${inst.id}" placeholder="You are a helpful assistant...">${inst.openai?.system_prompt || ''}</textarea>
                        </label>
                        <label>
                            Assistant Prompt: <textarea id="openai-assistant-prompt-${inst.id}" placeholder="Additional context...">${inst.openai?.assistant_prompt || ''}</textarea>
                        </label>
                        <button type="button" onclick="saveOpenAISettings('${inst.id}')">Save OpenAI Settings</button>
                    </form>
                </div>
                <div class="actions">
                    <button onclick="openTestModal('${inst.id}', '${inst.api_key}', '${inst.name}')"><i class="fas fa-paper-plane"></i> Send Test Message</button>
                    ${connectionStatus === 'Disconnected' || connectionStatus === 'Failed' ?
                      `<button onclick="requestQR('${inst.id}')"><i class="fas fa-qrcode"></i> Generate QR</button>` :
                      `<button onclick="disconnectInstance('${inst.id}')" class="danger"><i class="fas fa-unlink"></i> Disconnect</button>`
                    }
                    <a class="danger" href="?delete=${inst.id}"><i class="fas fa-trash"></i> Delete</a>
                </div>
            </div>
        `;
    }).catch(error => {
        console.error('Error getting connection status:', error);
        document.getElementById('instance-details').innerHTML = `
            <div class="instance-card">
                <p><strong>ID:</strong> <code>${inst.id}</code></p>
                <p><strong>Name:</strong> ${inst.name}</p>
                <p><strong>Port:</strong> ${inst.port}</p>
                <p><strong>Error:</strong> Could not fetch status</p>
                <p><strong>API Key:</strong> <span>${inst.api_key}</span> <button onclick="copyToClipboard('${inst.api_key}')">Copy</button></p>
                <div class="actions">
                    <button onclick="openTestModal('${inst.id}', '${inst.api_key}', '${inst.name}')"><i class="fas fa-paper-plane"></i> Send Test Message</button>
                    <a class="danger" href="?delete=${inst.id}"><i class="fas fa-trash"></i> Delete</a>
                </div>
            </div>
        `;
    });
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('API Key copied to clipboard!');
    }).catch(function(err) {
        console.error('Failed to copy: ', err);
    });
}

function refreshQR(id) {
    const img = document.getElementById('qr-image-' + id);
    img.src = '?qr=' + id + '&t=' + Date.now();
}

function connectInstance(id) {
    // Placeholder for connect
    alert('Connect functionality not implemented yet.');
}

function disconnectInstance(id) {
    if (!confirm('Are you sure you want to disconnect this instance?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'disconnect';
    input.value = id;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

function requestQR(id) {
    console.log('requestQR called with id:', id);
    
    // Validate instance ID format (security)
    if (!id || !/^[\w-]{1,64}$/.test(id)) {
        console.error('Invalid instance ID format:', id);
        alert('Invalid instance ID');
        return;
    }
    
    // Check if instance exists
    const instance = window.instances[id];
    if (!instance) {
        console.error('Instance not found:', id);
        alert('Instance not found');
        return;
    }
    
    // Check connection status
    if (instance.connection_status === 'connected') {
        alert('Instance is already connected to WhatsApp');
        return;
    }
    
    QRManager.setCurrentQRInstance(id);
    const modal = document.getElementById('qrModal');
    const title = document.getElementById('qrModalTitle');
    
    if (modal && title) {
        modal.style.display = 'block';
        title.textContent = 'Connect WhatsApp for ' + instance.name;
        refreshQRModal();
    } else {
        console.error('QR modal elements not found');
        alert('QR modal not available');
    }
}

function closeQRModal() {
    document.getElementById('qrModal').style.display = 'none';
}

function refreshQRModal() {
    console.log('refreshQRModal called');
    
    const img = document.getElementById('qrImage');
    if (!img) {
        console.error('QR image element not found');
        return;
    }
    
    const currentInstanceId = QRManager.getCurrentQRInstance();
    if (!currentInstanceId) {
        console.error('No current QR instance set');
        alert('No instance selected for QR generation');
        return;
    }
    
    // Show loading state
    img.style.display = 'none';
    let loadingDiv = document.getElementById('qrLoading');
    if (!loadingDiv) {
        loadingDiv = document.createElement('div');
        loadingDiv.id = 'qrLoading';
        loadingDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating QR Code...';
        loadingDiv.style.textAlign = 'center';
        loadingDiv.style.padding = '50px';
        loadingDiv.style.color = 'var(--accent)';
        img.parentNode.insertBefore(loadingDiv, img);
    }
    
    // Create request with timeout and abort controller
    const controller = QRManager.createRequestController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    
    // Use new qr-proxy.php endpoint - relative to current directory
    const proxyUrl = `./qr-proxy.php?id=${encodeURIComponent(currentInstanceId)}`;
    console.log('Fetching QR from:', proxyUrl);
    
    fetch(proxyUrl, {
        method: 'GET',
        signal: controller.signal,
        headers: {
            'Accept': 'application/json',
            'Cache-Control': 'no-cache'
        }
    })
    .then(response => {
        clearTimeout(timeoutId);
        
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        if (!response.ok) {
            return response.json().catch(() => ({ 
                success: false, 
                error: `HTTP ${response.status}: ${response.statusText}` 
            }));
        }
        
        return response.json();
    })
    .then(data => {
        // Remove loading indicator
        if (loadingDiv) {
            loadingDiv.remove();
        }
        
        console.log('QR response data:', data);
        
        if (data.success) {
            handleSuccessfulQRResponse(data, img);
        } else {
            handleFailedQRResponse(data, img);
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        
        // Remove loading indicator
        if (loadingDiv) {
            loadingDiv.remove();
        }
        
        console.error('QR fetch error:', error);
        
        if (error.name === 'AbortError') {
            handleQRTimeout(img);
        } else {
            handleNetworkError(error, img);
        }
    });
}

function handleSuccessfulQRResponse(data, img) {
    console.log('Handling successful QR response:', data);
    
    // Remove any existing status
    const existingStatus = document.getElementById('qrStatus');
    if (existingStatus) {
        existingStatus.remove();
    }
    
    if (data.qr_png) {
        // Use base64 PNG data directly
        img.src = `data:image/png;base64,${data.qr_png}`;
        img.style.display = 'block';
        img.alt = 'QR Code for WhatsApp Connection';
    } else if (data.qr_text) {
        // Generate QR from text using external service
        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(data.qr_text)}`;
        img.src = qrUrl;
        img.style.display = 'block';
        img.alt = 'QR Code for WhatsApp Connection';
    } else {
        img.src = '';
        img.style.display = 'none';
        showQRStatus('No QR data available', 'error');
        return;
    }
    
    // Add status information
    const statusInfo = document.createElement('div');
    statusInfo.id = 'qrStatus';
    statusInfo.className = 'qr-status';
    
    const freshness = data.fresh ? 'Fresh' : 'Expired';
    const fallback = data.fallback ? ' (Generated)' : ' (Cached)';
    const generatedTime = data.generated_at ? new Date(data.generated_at * 1000).toLocaleTimeString() : 'Unknown';
    
    statusInfo.innerHTML = `
        <p><strong>Status:</strong> ${freshness}${fallback}</p>
        <p><strong>Instance:</strong> ${data.instance_id}</p>
        <p><strong>Generated:</strong> ${generatedTime}</p>
        ${data.ttl ? `<p><strong>TTL:</strong> ${data.ttl}s</p>` : ''}
        ${data.last_seen ? `<p><strong>Last Seen:</strong> ${new Date(data.last_seen).toLocaleTimeString()}</p>` : ''}
    `;
    
    img.parentNode.appendChild(statusInfo);
}

function handleFailedQRResponse(data, img) {
    console.log('Handling failed QR response:', data);
    
    img.style.display = 'none';
    img.src = '';
    
    let errorMessage = 'Failed to generate QR code';
    let errorDetails = '';
    
    if (data.error) {
        errorMessage = data.error;
        if (data.instance_id) {
            errorDetails = ` (Instance: ${data.instance_id})`;
        }
    }
    
    showQRStatus(`${errorMessage}${errorDetails}`, 'error');
    
    if (data.status === 404) {
        showQRStatus('Instance not found. Please refresh the page and try again.', 'warning');
    } else if (data.status === 410) {
        showQRStatus('QR code has expired. Please wait for a new one to be generated.', 'warning');
    } else if (data.status === 500) {
        showQRStatus('Server error. Please try again later.', 'error');
    }
}

function handleQRTimeout(img) {
    img.style.display = 'block';
    img.alt = 'Request Timeout';
    img.src = '';
    showQRStatus('Request timed out. Please try again.', 'error');
}

function handleNetworkError(error, img) {
    img.style.display = 'block';
    img.alt = 'Network Error';
    img.src = '';
    showQRStatus(`Network error: ${error.message}`, 'error');
}

function showQRStatus(message, type) {
    const existingStatus = document.getElementById('qrStatus');
    if (existingStatus) {
        existingStatus.remove();
    }
    
    const statusDiv = document.createElement('div');
    statusDiv.id = 'qrStatus';
    statusDiv.className = `qr-status ${type}`;
    statusDiv.innerHTML = `<p><strong>${type.charAt(0).toUpperCase() + type.slice(1)}:</strong> ${message}</p>`;
    
    const img = document.getElementById('qrImage');
    if (img && img.parentNode) {
        img.parentNode.appendChild(statusDiv);
    }
}

// Connect to WhatsApp instance - simplified to only request QR
function connectToWhatsApp(instanceId) {
    console.log('Connecting to WhatsApp for instance:', instanceId);
    
    // For now, just request QR code for connection
    // Real connection will be handled by WhatsApp service
    requestQR(instanceId);
}

// Check connection status using proper API endpoints
async function checkConnectionStatus(instanceId) {
    try {
        const instance = window.instances[instanceId];
        if (!instance) return 'Unknown';
        
        // Try health endpoint first (faster)
        let response = await fetch(`http://localhost:${instance.port}/health`);
        if (response.ok) {
            const data = await response.json();
            return data.whatsappConnected ? 'Connected' : 'Disconnected';
        }
        
        // Fallback to status endpoint
        response = await fetch(`http://localhost:${instance.port}/status`);
        if (response.ok) {
            const data = await response.json();
            return data.connectionStatus || 'Unknown';
        }
        
        // Fallback to instances data
        return instance.connection_status ? 
            (instance.connection_status === 'connected' ? 'Connected' : 'Disconnected') : 
            'Disconnected';
    } catch (error) {
        console.error('Status check error:', error);
        return 'Unknown';
    }
}

// Initialize connection status checking for all instances
async function initializeConnectionStatuses() {
    if (!window.instances) return;
    console.log('Connection statuses will be managed by the main service');
}

// Disconnect instance - simplified
function disconnectInstance(id) {
    if (!confirm('Are you sure you want to disconnect this instance?')) return;
    
    alert('Disconnect functionality should be implemented by the main WhatsApp service');
    
    // Refresh the instance details to reflect any changes
    if (window.selectInstance) {
        window.selectInstance(id);
    }
}

// Save OpenAI settings
function saveOpenAISettings(instanceId) {
    const enabled = document.getElementById(`openai-enabled-${instanceId}`).checked;
    const apiKey = document.getElementById(`openai-api-key-${instanceId}`).value;
    const systemPrompt = document.getElementById(`openai-system-prompt-${instanceId}`).value;
    const assistantPrompt = document.getElementById(`openai-assistant-prompt-${instanceId}`).value;

    fetch('api.php', {
        method: 'POST',
        headers: {
            'x-api-key': window.instances[instanceId].api_key,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'save_openai',
            openai: {
                enabled: enabled,
                api_key: apiKey,
                system_prompt: systemPrompt,
                assistant_prompt: assistantPrompt
            }
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('OpenAI settings saved successfully!');
            // Reload instances to reflect changes
            location.reload();
        } else {
            alert('Error saving settings: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Network error: ' + error.message);
    });
}

// Expor funções usadas em atributos onclick no HTML
window.selectInstance       = selectInstance;
window.disconnectInstance   = disconnectInstance;
window.requestQR            = requestQR;
window.openTestModal        = openTestModal;
window.closeTestModal       = closeTestModal;
window.closeQRModal         = closeQRModal;
window.refreshQRModal       = refreshQRModal;
window.connectToWhatsApp    = connectToWhatsApp;
window.checkConnectionStatus = checkConnectionStatus;
window.copyToClipboard      = copyToClipboard;
window.saveOpenAISettings   = saveOpenAISettings;
