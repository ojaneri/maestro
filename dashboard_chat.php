<?php
// dashboard_chat.php - Chat Dashboard UI matching existing design
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';
if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

session_start();
if (!isset($_SESSION['auth'])) {
    header("Location: /api/envio/wpp/");
    exit;
}

// Get instance from URL parameter
$instanceId = $_GET['instance'] ?? null;
if (!$instanceId) {
    header("Location: /api/envio/wpp/");
    exit;
}

debug_log('Dashboard chat loaded for instance: ' . $instanceId);

// Get instance details
$instance = loadInstanceRecordFromDatabase($instanceId);

if (!$instance) {
    header("Location: /api/envio/wpp/");
    exit;
}

// Check if server is running
function isPortOpen($host, $port, $timeout = 1) {
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    } else {
        return false;
    }
}

$isRunning = isPortOpen('localhost', $instance['port']);
$connectionStatus = $isRunning ? 'connected' : 'disconnected';

if ($isRunning) {
    // Try to get actual connection status
    $ch = curl_init("http://127.0.0.1:{$instance['port']}/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $resp = curl_exec($ch);
    curl_close($ch);
    
    if ($resp) {
        $data = json_decode($resp, true);
        if ($data && isset($data['whatsappConnected'])) {
            $connectionStatus = $data['whatsappConnected'] ? 'connected' : 'disconnected';
        }
    }
}

debug_log('Instance status: ' . $connectionStatus);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Chat Dashboard - <?= htmlspecialchars($instance['name']) ?></title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#2563EB',
            dark: '#1E293B',
            light: '#F1F5F9',
            mid: '#CBD5E1',
            success: '#22C55E',
            alert: '#F59E0B',
            error: '#EF4444'
          }
        }
      }
    }
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    html, body { font-family: Inter, system-ui, sans-serif; }
    
    /* Chat specific styles */
    .chat-container {
      height: calc(100vh - 120px);
    }
    
    .chat-sidebar {
      width: 320px;
      border-right: 1px solid #CBD5E1;
    }
    
    .chat-main {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    
    .message-bubble {
      max-width: 70%;
      word-wrap: break-word;
    }
    
    .message-user {
      background: #2563EB;
      color: white;
      margin-left: auto;
      border-radius: 18px 18px 4px 18px;
    }
    
    .message-assistant {
      background: #F1F5F9;
      color: #1E293B;
      margin-right: auto;
      border-radius: 18px 18px 18px 4px;
    }
    
    .contact-item {
      border-bottom: 1px solid #F1F5F9;
      transition: background-color 0.2s;
    }
    
    .contact-item:hover {
      background: #F8FAFC;
    }
    
    .contact-item.active {
      background: #EFF6FF;
      border-right: 3px solid #2563EB;
    }
    
    .typing-indicator {
      animation: pulse 1.5s infinite;
    }
    
    @keyframes pulse {
      0%, 100% { opacity: 0.4; }
      50% { opacity: 1; }
    }
  </style>
</head>

<body class="bg-light text-dark">
<div class="min-h-screen flex">

  <!-- CHAT SIDEBAR - CONTACTS -->
  <aside class="chat-sidebar bg-white hidden md:flex flex-col">
    <div class="p-6 border-b border-mid">
      <div class="flex items-center gap-3 mb-4">
        <div class="w-10 h-10 rounded-xl bg-primary"></div>
        <div>
          <div class="text-lg font-semibold text-dark">Chat IA</div>
          <div class="text-xs text-slate-500"><?= htmlspecialchars($instance['name']) ?></div>
        </div>
      </div>

      <input id="searchInput" class="w-full px-3 py-2 rounded-xl bg-light border border-mid text-sm"
             placeholder="Buscar contatos...">
    </div>

    <div class="flex-1 overflow-y-auto" id="contactsList">
      <!-- Contacts will be loaded via JavaScript -->
      <div class="p-4 text-center text-slate-500">
        <div class="animate-spin w-6 h-6 border-2 border-primary border-t-transparent rounded-full mx-auto mb-2"></div>
        Carregando contatos...
      </div>
    </div>

    <div class="p-4 border-t border-mid">
      <a href="/api/envio/wpp/?instance=<?= $instanceId ?>" 
         class="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-dark">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
        </svg>
        Voltar ao Painel
      </a>
    </div>
  </aside>

  <!-- CHAT MAIN AREA -->
  <main class="chat-main">
    <!-- Chat Header -->
    <div class="bg-white border-b border-mid p-4 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-mid flex items-center justify-center">
          <svg class="w-5 h-5 text-slate-600" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
          </svg>
        </div>
        <div>
          <div class="font-medium" id="chatContactName">Selecione um contato</div>
          <div class="text-xs text-slate-500" id="chatStatus">Aguardando seleção...</div>
        </div>
      </div>
      
      <div class="flex items-center gap-2">
        <?php if ($connectionStatus === 'connected'): ?>
          <span class="px-2 py-1 rounded-full bg-success/10 text-success text-xs font-medium">
            Conectado
          </span>
        <?php else: ?>
          <span class="px-2 py-1 rounded-full bg-error/10 text-error text-xs font-medium">
            Desconectado
          </span>
        <?php endif; ?>
        
        <button id="refreshBtn" class="p-2 rounded-lg bg-light hover:bg-mid transition-colors">
          <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
          </svg>
        </button>
      </div>
    </div>

    <!-- Messages Area -->
    <div class="flex-1 overflow-y-auto p-4 space-y-3" id="messagesArea">
      <div class="text-center text-slate-500 py-8">
        <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
        </svg>
        <p>Selecione um contato para ver a conversa</p>
      </div>
    </div>

    <!-- Message Input -->
    <div class="bg-white border-t border-mid p-4">
      <form id="messageForm" class="flex gap-2">
        <input type="text" 
               id="messageInput" 
               class="flex-1 px-4 py-2 rounded-xl border border-mid bg-light focus:outline-none focus:border-primary"
               placeholder="Digite sua mensagem..."
               disabled>
        <button type="submit" 
                id="sendBtn"
                class="px-6 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed"
                disabled>
          Enviar
        </button>
      </form>
    </div>
  </main>
</div>

<script>
const INSTANCE_ID = '<?= $instanceId ?>';
const API_BASE = 'http://127.0.0.1:<?= $instance['port'] ?>';

// State
let selectedContact = null;
let contacts = [];
let messages = {};
let isLoading = false;

// Elements
const contactsList = document.getElementById('contactsList');
const messagesArea = document.getElementById('messagesArea');
const searchInput = document.getElementById('searchInput');
const messageInput = document.getElementById('messageInput');
const messageForm = document.getElementById('messageForm');
const sendBtn = document.getElementById('sendBtn');
const chatContactName = document.getElementById('chatContactName');
const chatStatus = document.getElementById('chatStatus');
const refreshBtn = document.getElementById('refreshBtn');

// Load contacts
async function loadContacts() {
    try {
        const response = await fetch(`${API_BASE}/contacts`);
        const data = await response.json();
        
        if (data.ok) {
            contacts = data.contacts;
            renderContacts();
        }
    } catch (error) {
        console.error('Error loading contacts:', error);
        contactsList.innerHTML = '<div class="p-4 text-center text-error">Erro ao carregar contatos</div>';
    }
}

// Render contacts list
function renderContacts() {
    const searchTerm = searchInput.value.toLowerCase();
    const filteredContacts = contacts.filter(contact => 
        contact.contact_name?.toLowerCase().includes(searchTerm) ||
        contact.remote_jid.includes(searchTerm)
    );
    
    if (filteredContacts.length === 0) {
        contactsList.innerHTML = '<div class="p-4 text-center text-slate-500">Nenhum contato encontrado</div>';
        return;
    }
    
    contactsList.innerHTML = filteredContacts.map(contact => {
        const lastMessage = contact.last_message || 'Nenhuma mensagem';
        const lastRole = contact.last_role === 'user' ? 'Você: ' : '';
        const time = new Date(contact.last_message_at).toLocaleTimeString('pt-BR', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
        
        return `
            <div class="contact-item p-4 cursor-pointer ${selectedContact === contact.remote_jid ? 'active' : ''}" 
                 onclick="selectContact('${contact.remote_jid}', '${contact.contact_name || contact.remote_jid}')">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-white font-medium">
                        ${(contact.contact_name || contact.remote_jid).charAt(0).toUpperCase()}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-dark truncate">
                            ${contact.contact_name || contact.remote_jid}
                        </div>
                        <div class="text-sm text-slate-500 truncate">
                            ${lastRole}${lastMessage.substring(0, 50)}...
                        </div>
                    </div>
                    <div class="text-xs text-slate-400">
                        ${time}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// Select contact and load messages
async function selectContact(remoteJid, contactName) {
    selectedContact = remoteJid;
    chatContactName.textContent = contactName;
    chatStatus.textContent = 'Carregando mensagens...';
    
    // Update UI
    document.querySelectorAll('.contact-item').forEach(item => {
        item.classList.remove('active');
    });
    event.target.closest('.contact-item').classList.add('active');
    
    // Enable message input
    messageInput.disabled = false;
    sendBtn.disabled = false;
    
    // Load messages
    await loadMessages(remoteJid);
}

// Load messages for contact
async function loadMessages(remoteJid) {
    try {
        isLoading = true;
        chatStatus.textContent = 'Carregando mensagens...';
        
        const response = await fetch(`${API_BASE}/history?contact=${encodeURIComponent(remoteJid)}`);
        const data = await response.json();
        
        if (data.ok) {
            messages[remoteJid] = data.messages;
            renderMessages(remoteJid);
            chatStatus.textContent = `${data.messages.length} mensagens`;
        }
    } catch (error) {
        console.error('Error loading messages:', error);
        chatStatus.textContent = 'Erro ao carregar mensagens';
    } finally {
        isLoading = false;
    }
}

// Render messages
function renderMessages(remoteJid) {
    const contactMessages = messages[remoteJid] || [];
    
    if (contactMessages.length === 0) {
        messagesArea.innerHTML = `
            <div class="text-center text-slate-500 py-8">
                <p>Nenhuma mensagem ainda</p>
                <p class="text-sm mt-2">Inicie a conversa!</p>
            </div>
        `;
        return;
    }
    
    messagesArea.innerHTML = contactMessages.map(msg => {
        const isUser = msg.role === 'user';
        const time = new Date(msg.timestamp).toLocaleTimeString('pt-BR', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
        
        return `
            <div class="flex ${isUser ? 'justify-end' : 'justify-start'}">
                <div class="message-bubble ${isUser ? 'message-user' : 'message-assistant'} p-3">
                    <div class="text-sm">${escapeHtml(msg.content)}</div>
                    <div class="text-xs mt-1 opacity-70">${time}</div>
                </div>
            </div>
        `;
    }).join('');
    
    // Scroll to bottom
    messagesArea.scrollTop = messagesArea.scrollHeight;
}

// Send message
async function sendMessage(e) {
    e.preventDefault();
    
    const message = messageInput.value.trim();
    if (!message || !selectedContact) return;
    
    // Add to UI immediately
    const tempMessage = {
        role: 'user',
        content: message,
        timestamp: new Date().toISOString()
    };
    
    if (!messages[selectedContact]) {
        messages[selectedContact] = [];
    }
    messages[selectedContact].push(tempMessage);
    renderMessages(selectedContact);
    
    // Clear input
    messageInput.value = '';
    
    // Send to API
    try {
        const response = await fetch(`${API_BASE}/send-message`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to: selectedContact,
                message: message
            })
        });
        
        if (!response.ok) {
            throw new Error('Failed to send message');
        }
        
        // Reload messages to get the latest state
        setTimeout(() => loadMessages(selectedContact), 1000);
        
    } catch (error) {
        console.error('Error sending message:', error);
        chatStatus.textContent = 'Erro ao enviar mensagem';
    }
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Event listeners
searchInput.addEventListener('input', renderContacts);
messageForm.addEventListener('submit', sendMessage);
refreshBtn.addEventListener('click', () => {
    if (selectedContact) {
        loadMessages(selectedContact);
    } else {
        loadContacts();
    }
});

// Auto-refresh contacts every 30 seconds
setInterval(loadContacts, 30000);

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    loadContacts();
    
    // Check if instance is connected
    if ('<?= $connectionStatus ?>' !== 'connected') {
        chatStatus.textContent = 'Instância desconectada';
    }
});
</script>
</body>
</html>
