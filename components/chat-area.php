<?php
/**
 * Chat Area Component
 * Main chat window with messages
 */

if (!defined('INCLUDED')) exit;

global $messages, $currentChat, $chatList, $instanceId;

// Find current chat info
$currentChatInfo = null;
foreach ($chatList as $chat) {
    if ($chat['id'] === $currentChat) {
        $currentChatInfo = $chat;
        break;
    }
}

$chatName = $currentChatInfo ? ($currentChatInfo['name'] ?? $currentChatInfo['phone']) : 'Chat';
$chatPhone = $currentChatInfo['phone'] ?? '';
$chatStatus = $currentChatInfo['status'] ?? 'offline';
$isGroup = $currentChatInfo['isGroup'] ?? false;
$avatar = !empty($currentChatInfo['profilePictureUrl']) ?
    htmlspecialchars($currentChatInfo['profilePictureUrl']) :
    "https://ui-avatars.com/api/?name=" . urlencode($chatName) . "&background=random&color=fff&size=128";

?>

<div class="flex-1 flex flex-col h-full bg-slate-50 relative"
     id="chat-area"
     data-chat-id="<?= htmlspecialchars($currentChat) ?>">
    <!-- Chat Header -->
    <header class="h-16 bg-white border-b border-slate-200/60 flex items-center justify-between px-4 flex-shrink-0 sticky top-0 z-20"
            id="chat-header">
        <div class="flex items-center gap-3">
            <button id="backBtn" class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-slate-100 transition-colors">
                <svg class="w-5 h-5 text-slate-600" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
            <div class="relative">
                <img src="<?= $avatar ?>" alt="<?= $chatName ?>"
                     class="w-10 h-10 rounded-full object-cover">
                <?php if ($chatStatus === 'online'): ?>
                    <span class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-white bg-green-500"></span>
                <?php endif; ?>
            </div>
            <div class="flex flex-col">
                <h2 class="font-semibold text-dark text-base"><?= htmlspecialchars($chatName) ?></h2>
                <p class="text-xs text-slate-400 flex items-center gap-1">
                    <?= htmlspecialchars($chatPhone) ?>
                    <?php if ($isGroup): ?>
                        <span class="px-1.5 py-0.5 bg-blue-50 text-blue-600 text-[10px] rounded-full">Grupo</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="flex items-center gap-1">
            <button class="p-2.5 rounded-xl hover:bg-slate-100 transition-colors text-slate-500 hover:text-slate-700"
                    title="Pesquisar">
                <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"></path>
                </svg>
            </button>
            <button class="p-2.5 rounded-xl hover:bg-slate-100 transition-colors text-slate-500 hover:text-slate-700"
                    title="Opções">
                <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path>
                </svg>
            </button>
        </div>
    </header>

    <!-- Messages Container -->
    <div class="flex-1 overflow-y-auto p-4 space-y-4 custom-scrollbar"
         id="messages-container"
         data-instance="<?= htmlspecialchars($instanceId) ?>">
        <?php if (empty($messages)): ?>
            <div class="h-full flex items-center justify-center">
                <div class="text-center">
                    <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-blue-50 flex items-center justify-center">
                        <svg class="w-10 h-10 text-blue-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-slate-700">Nenhuma mensagem ainda</h3>
                    <p class="text-sm text-slate-400 mt-1">Enviar uma mensagem para iniciar a conversa</p>
                </div>
            </div>
        <?php else: ?>
            <?php
            $lastDate = '';
            foreach ($messages as $msg):
                $isMe = $msg['fromMe'] ?? false;
                $content = $msg['content'] ?? '';
                $time = $msg['time'] ?? '';
                $type = $msg['type'] ?? 'text';
                $status = $msg['status'] ?? '';
                $msgId = $msg['id'] ?? '';

                // Group messages by date
                $msgDate = $msg['date'] ?? '';
                if ($msgDate !== $lastDate && !empty($msgDate)):
                    $lastDate = $msgDate;
            ?>
                    <div class="flex justify-center my-4">
                        <span class="px-3 py-1 bg-slate-100 text-slate-500 text-xs rounded-full">
                            <?= htmlspecialchars($msgDate) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="flex <?= $isMe ? 'justify-end' : 'justify-start' ?>">
                    <div class="max-w-[75%] <?= $isMe ? 'order-2' : 'order-1' ?>">
                        <?php if (!empty($msg['senderName']) && !$isMe): ?>
                            <span class="ml-1 mb-1 text-xs text-slate-500 font-medium">
                                <?= htmlspecialchars($msg['senderName']) ?>
                            </span>
                        <?php endif; ?>

                        <div class="message-bubble <?= $isMe ? 'message-sent' : 'message-received' ?> <?= $type ? 'message-' . htmlspecialchars($type) : '' ?>"
                             data-msg-id="<?= htmlspecialchars($msgId) ?>">
                            <?php
                            switch ($type):
                                case 'image':
                            ?>
                                    <img src="<?= htmlspecialchars($content) ?>"
                                         alt="Imagem"
                                         class="max-w-full rounded-lg cursor-pointer hover:opacity-90 transition-opacity"
                                         onclick="viewImage('<?= htmlspecialchars($content) ?>')">
                                <?php
                                    break;
                                case 'document':
                                    $docIcon = getDocIcon($msg['fileName'] ?? 'file');
                                ?>
                                    <a href="<?= htmlspecialchars($content) ?>"
                                       download="<?= htmlspecialchars($msg['fileName'] ?? 'arquivo') ?>"
                                       class="flex items-center gap-2 p-2 bg-white/50 rounded-lg hover:bg-white/80 transition-colors">
                                        <span class="text-2xl"><?= $docIcon ?></span>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-medium truncate max-w-[150px]">
                                                <?= htmlspecialchars($msg['fileName'] ?? 'Documento') ?>
                                            </span>
                                            <span class="text-xs text-slate-400">
                                                Baixar
                                            </span>
                                        </div>
                                    </a>
                                <?php
                                    break;
                                case 'audio':
                                ?>
                                    <audio controls class="w-full max-w-[200px]">
                                        <source src="<?= htmlspecialchars($content) ?>" type="audio/mpeg">
                                        Áudio não suportado
                                    </audio>
                                <?php
                                    break;
                                case 'video':
                                ?>
                                    <video controls class="max-w-full rounded-lg">
                                        <source src="<?= htmlspecialchars($content) ?>" type="video/mp4">
                                        Vídeo não suportado
                                    </video>
                                <?php
                                    break;
                                default:
                                    // Handle WhatsApp formatting
                                    $formattedContent = formatWhatsAppText($content);
                                    echo $formattedContent;
                            endswitch;
                            ?>

                            <div class="message-meta <?= $isMe ? 'justify-end' : 'justify-start' ?>">
                                <span class="text-[10px] opacity-70"><?= htmlspecialchars($time) ?></span>
                                <?php if ($isMe && !empty($status)): ?>
                                    <span class="message-status">
                                        <?= getStatusIcon($status) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Quick Replies -->
    <?php if (!empty($quickReplies)): ?>
        <div class="px-4 py-2 border-t border-slate-200/60 bg-white flex gap-2 overflow-x-auto custom-scrollbar flex-shrink-0">
            <?php foreach ($quickReplies as $reply): ?>
                <button class="quick-reply-btn flex-shrink-0 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm rounded-full transition-colors"
                        data-message="<?= htmlspecialchars($reply) ?>">
                    <?= htmlspecialchars(mb_strlen($reply) > 30 ? mb_substr($reply, 0, 30) . '...' : $reply) ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Message Input -->
    <div class="p-3 bg-white border-t border-slate-200/60 flex-shrink-0"
         id="message-input-area">
        <form id="messageForm" class="flex items-end gap-2">
            <div class="flex-1 relative">
                <textarea id="messageInput"
                          placeholder="Digite uma mensagem..."
                          rows="1"
                          class="w-full pl-4 pr-24 py-2.5 bg-slate-50 border-0 rounded-2xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all placeholder-slate-400"
                          style="min-height: 44px; max-height: 120px;"></textarea>
                <input type="file" id="audioInput" accept="audio/*" class="hidden">
                <div class="absolute right-2 bottom-2 flex gap-1">
                    <button type="button" id="audioBtn"
                            class="p-1.5 rounded-lg hover:bg-slate-200 transition-colors text-slate-400 hover:text-slate-600"
                            title="Enviar áudio">
                        <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M7 4a3 3 0 016 0v6a3 3 0 11-6 0V4zm4 10.93A7.001 7.001 0 0017 8a1 1 0 10-2 0A5 5 0 015 8a1 1 0 00-2 0 7.001 7.001 0 006 6.93V17H6a1 1 0 100 2h8a1 1 0 100-2h-3v-2.07z"></path>
                        </svg>
                    </button>
                    <button type="button" id="attachBtn"
                            class="p-1.5 rounded-lg hover:bg-slate-200 transition-colors text-slate-400 hover:text-slate-600"
                            title="Anexar arquivo">
                        <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z"></path>
                            <path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z"></path>
                        </svg>
                    </button>
                </div>
            </div>
            <button type="submit" id="sendBtn"
                    class="p-2.5 bg-blue-600 text-white rounded-2xl hover:bg-blue-700 transition-colors shadow-sm shadow-blue-200 flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed"
                    title="Enviar mensagem (Enter)">
                <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"></path>
                </svg>
            </button>
        </form>
    </div>
</div>

<?php
// Helper functions for this component
function getDocIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => '📄',
        'doc' => '📝',
        'docx' => '📝',
        'xls' => '📊',
        'xlsx' => '📊',
        'ppt' => '📑',
        'pptx' => '📑',
        'zip' => '📦',
        'rar' => '📦',
    ];
    return $icons[$ext] ?? '📎';
}

function formatWhatsAppText($text) {
    // Escape HTML first
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Format URLs
    $urlPattern = '/(https?:\/\/[^\s]+)/g';
    $text = preg_replace($urlPattern, '<a href="$1" target="_blank" class="text-blue-600 hover:underline">$1</a>', $text);

    // Bold
    $text = preg_replace('/\*([^*]+)\*/', '<strong>$1</strong>', $text);

    // Italic
    $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text);

    // Strikethrough
    $text = preg_replace('/~([^~]+)~/', '<del>$1</del>', $text);

    // Code
    $text = preg_replace('/`([^`]+)`/', '<code class="bg-slate-100 px-1 rounded text-sm">$1</code>', $text);

    // Multi-line code
    $text = preg_replace('/```([\s\S]+?)```/', '<pre class="bg-slate-800 text-slate-100 p-2 rounded-lg text-sm overflow-x-auto my-2"><code>$1</code></pre>', $text);

    // Convert newlines to <br>
    $text = nl2br($text);

    return $text;
}

function getStatusIcon($status) {
    $icons = [
        'sent' => '<svg class="w-3.5 h-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm3.707 8.707a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"></path></svg>',
        'delivered' => '<svg class="w-3.5 h-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm3.707 8.707a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"></path><path d="M10 18a8 8 0 100-16 8 8 0 000 16z" fill="none" stroke="currentColor" stroke-width="1"></path></svg>',
        'read' => '<svg class="w-3.5 h-3.5 text-blue-500" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zm3.707 8.707a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"></path><path d="M10 18a8 8 0 100-16 8 8 0 000 16z" fill="none" stroke="currentColor" stroke-width="1"></path></svg>',
        'failed' => '<svg class="w-3.5 h-3.5 text-red-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>',
    ];
    return $icons[$status] ?? '';
}
?>

<style>
.message-bubble {
    padding: 0.75rem 1rem;
    border-radius: 1.25rem;
    word-wrap: break-word;
    max-width: 100%;
}

.message-received {
    background: white;
    border-bottom-left-radius: 0.5rem;
    border: 1px solid #e2e8f0;
}

.message-sent {
    background: #3b82f6;
    color: white;
    border-bottom-right-radius: 0.5rem;
}

.message-sent a {
    color: #93c5fd;
}

.message-sent a:hover {
    color: #bfdbfe;
}

.message-image {
    padding: 0.25rem;
    background: transparent !important;
    border: none !important;
}

.message-document {
    padding: 0.5rem;
}

.message-meta {
    display: flex;
    gap: 0.25rem;
    margin-top: 0.25rem;
}

.message-received .message-meta {
    flex-direction: row;
}

.message-sent .message-meta {
    flex-direction: row-reverse;
}

#messageInput:focus {
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
}

.quick-reply-btn {
    transition: all 0.2s ease;
}

.quick-reply-btn:hover {
    transform: translateY(-1px);
}
</style>
