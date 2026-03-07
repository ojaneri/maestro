<?php
/**
 * Header Component
 * Shared header with instance status and actions
 */

if (!defined('INCLUDED')) exit;

global $selectedInstance, $statuses, $connectionStatuses, $selectedInstanceId;
?>

<div class="instance-sticky-header">
    <div class="text-sm text-slate-500">Instância selecionada</div>
    <div class="flex items-baseline gap-2">
        <div class="font-semibold text-dark"><?= htmlspecialchars($selectedInstance['name'] ?? 'Nenhuma instância') ?></div>
        <span class="text-[11px] tracking-[0.3em] uppercase text-slate-500">Conversas</span>
    </div>
</div>

<section class="card-soft mt-4 border-0">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="text-xs uppercase tracking-[0.3em] text-slate-400">Instância</div>
            <div class="text-3xl font-semibold text-dark"><?= htmlspecialchars($selectedInstance['name'] ?? 'Nenhuma instância') ?></div>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php
                $instanceStatus = $statuses[$selectedInstanceId] ?? 'Stopped';
                $connectionState = strtolower($connectionStatuses[$selectedInstanceId] ?? 'disconnected');
                $serverBadge = $instanceStatus === 'Running' ? 'Servidor OK' : 'Parado';
                $connectionBadge = $connectionState === 'connected' ? 'WhatsApp Conectado' : 'WhatsApp Desconectado';
                $connectionBadgeClass = $connectionState === 'connected' ? 'connection' : 'disconnect';
                ?>
                <span class="badge-pill <?= $instanceStatus === 'Running' ? 'server' : 'disconnect' ?>">
                    <?= htmlspecialchars($serverBadge) ?>
                </span>
                <span class="badge-pill <?= $connectionBadgeClass ?>">
                    <?= htmlspecialchars($connectionBadge) ?>
                </span>
            </div>
            <?php if ($selectedInstanceId): ?>
                <div class="mt-4 flex flex-wrap gap-2 text-[11px]">
                    <a href="index.php?instance=<?= urlencode($selectedInstanceId) ?>" class="text-[11px] px-2.5 py-1 rounded-full border border-slate-300 text-slate-600 flex items-center gap-1 hover:bg-slate-100 transition">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"></path>
                        </svg>
                        Voltar
                    </a>
                    <a href="grupos.php?instance=<?= urlencode($selectedInstanceId) ?>" class="text-[11px] px-2.5 py-1 rounded-full border border-slate-300 text-slate-600 flex items-center gap-1 hover:bg-slate-100 transition">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M7 7a3 3 0 116 0v1h1a2 2 0 012 2v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5a2 2 0 012-2h1V7z"></path>
                        </svg>
                        Grupos
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
