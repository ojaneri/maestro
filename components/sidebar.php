<?php
/**
 * Sidebar Component
 * Conversation list sidebar
 */

if (!defined('INCLUDED')) exit;

global $chatList, $currentChat, $conversasPorData, $instanceId;

?>

<aside class="h-full overflow-y-auto custom-scrollbar bg-white rounded-2xl shadow-sm border border-slate-200/60"
       id="sidebar">
    <div class="p-3 sticky top-0 z-10 bg-white/95 backdrop-blur-sm rounded-t-2xl border-b border-slate-100"
         id="sidebar-header">
        <div class="relative">
            <input type="text" id="searchInput"
                   placeholder="Buscar conversa..."
                   class="w-full pl-9 pr-3.5 py-2 bg-slate-50 border-0 rounded-xl text-sm placeholder-slate-400
                          focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" viewBox="0 0 20 20"
                 fill="currentColor">
                <path fill-rule="evenodd"
                      d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
                      clip-rule="evenodd"></path>
            </svg>
        </div>

        <div class="mt-3 flex gap-1.5">
            <button class="filter-btn active px-2.5 py-1 text-xs font-medium rounded-lg bg-blue-50 text-blue-600"
                    data-filter="all">Todas</button>
            <button class="filter-btn px-2.5 py-1 text-xs font-medium rounded-lg text-slate-500 hover:bg-slate-50 transition-colors"
                    data-filter="unread">Não lidas</button>
            <button class="filter-btn px-2.5 py-1 text-xs font-medium rounded-lg text-slate-500 hover:bg-slate-50 transition-colors"
                    data-filter="pinned">Fixadas</button>
        </div>
    </div>

    <div id="chatList" class="p-1.5 space-y-1 min-h-[400px]">
        <?php if (empty($chatList)): ?>
            <div class="p-4 text-center text-slate-400 text-sm">
                <svg class="w-12 h-12 mx-auto mb-3 text-slate-300" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
                Nenhuma conversa encontrada
            </div>
        <?php else: ?>
            <?php
            $lastDate = '';
            foreach ($chatList as $chat):
                $isSelected = $chat['id'] === $currentChat;
                $chatId = htmlspecialchars($chat['id']);
                $name = htmlspecialchars($chat['name'] ?? $chat['phone']);
                $phone = htmlspecialchars($chat['phone']);
                $lastMessage = htmlspecialchars($chat['lastMessage'] ?? 'Sem mensagens');
                $time = htmlspecialchars($chat['time'] ?? '');
                $unread = $chat['unread'] ?? 0;
                $isPinned = $chat['isPinned'] ?? false;
                $isMuted = $chat['isMuted'] ?? false;
                $avatar = !empty($chat['profilePictureUrl']) ?
                    htmlspecialchars($chat['profilePictureUrl']) :
                    "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=random&color=fff&size=128";
                $statusClass = $chat['status'] === 'online' ? 'status-online' : ($chat['status'] === 'typing' ? 'status-typing' : 'status-offline');
                $messagePreview = mb_strlen($lastMessage) > 45 ? mb_substr($lastMessage, 0, 45) . '...' : $lastMessage;
                $isGroup = $chat['isGroup'] ?? false;
            ?>
                <div class="chat-item group relative flex items-center gap-3 p-2.5 rounded-xl cursor-pointer transition-all
                            <?= $isSelected ? 'bg-blue-50 ring-1 ring-blue-500/30' : 'hover:bg-slate-50' ?>"
                     data-chat-id="<?= $chatId ?>"
                     data-filter="<?= $unread > 0 ? 'unread' : 'all' ?>"
                     data-pinned="<?= $isPinned ? 'true' : 'false' ?>">
                    <div class="relative flex-shrink-0">
                        <img src="<?= $avatar ?>" alt="<?= $name ?>"
                             class="w-12 h-12 rounded-full object-cover <?= $isGroup ? 'ring-2 ring-slate-200' : '' ?>">
                        <?php if ($chat['status'] === 'online'): ?>
                            <span class="absolute bottom-0 right-0 w-3.5 h-3.5 rounded-full border-2 border-white bg-green-500"></span>
                        <?php elseif ($chat['status'] === 'typing'): ?>
                            <span class="absolute -bottom-1 -right-1 flex bg-white rounded-full p-0.5 shadow-sm">
                                <span class="typing-indicator"></span>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline justify-between gap-2">
                            <div class="flex items-center gap-1.5">
                                <?php if ($isGroup): ?>
                                    <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M7 7a3 3 0 116 0v1h1a2 2 0 012 2v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5a2 2 0 012-2h1V7z"></path>
                                    </svg>
                                <?php endif; ?>
                                <h3 class="font-semibold text-sm text-dark truncate <?= $unread > 0 ? 'text-blue-600' : '' ?>">
                                    <?= $name ?>
                                </h3>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <?php if ($isPinned): ?>
                                    <svg class="w-3.5 h-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z"></path>
                                    </svg>
                                <?php endif; ?>
                                <?php if ($isMuted): ?>
                                    <svg class="w-3 h-3 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M5.5 6.5L9 10V4l1.5 3.5h-3L6 4v6l-.5-1.5zm9 3.5V15l1.5-3.5h3l1.5 3.5v-8l-1.5-3.5h-3L14.5 4v6z"></path>
                                    </svg>
                                <?php endif; ?>
                                <span class="text-[11px] <?= $unread > 0 ? 'font-semibold text-blue-600' : 'text-slate-400' ?>">
                                    <?= $time ?>
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <p class="text-xs text-slate-500 truncate flex-1 <?= $unread > 0 ? 'font-medium text-slate-700' : '' ?>">
                                <?= $messagePreview ?>
                            </p>
                            <?php if ($unread > 0): ?>
                                <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-500 text-white text-[10px] font-bold flex items-center justify-center">
                                    <?= min($unread, 99) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="p-3 sticky bottom-0 bg-white/95 backdrop-blur-sm border-t border-slate-100 rounded-b-2xl">
        <button id="newChatBtn" class="w-full py-2.5 bg-blue-600 text-white rounded-xl font-medium text-sm hover:bg-blue-700 transition-colors flex items-center justify-center gap-2 shadow-sm shadow-blue-200">
            <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"></path>
            </svg>
            Nova conversa
        </button>
    </div>
</aside>

<style>
.chat-item {
    transition: all 0.2s ease;
}

.chat-item:hover {
    transform: translateX(2px);
}

.chat-item:active {
    transform: scale(0.99);
}

.status-online {
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px #22c55e;
}

.status-typing {
    animation: pulse 1s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.typing-indicator {
    display: flex;
    gap: 2px;
}

.typing-indicator::before,
.typing-indicator::after,
.typing-indicator span {
    content: '';
    width: 4px;
    height: 4px;
    background: #3b82f6;
    border-radius: 50%;
    animation: bounce 1.4s infinite ease-in-out both;
}

.typing-indicator::before { animation-delay: -0.32s; }
.typing-indicator::after { animation-delay: -0.16s; }

@keyframes bounce {
    0%, 80%, 100% { transform: scale(0); }
    40% { transform: scale(1); }
}
</style>
