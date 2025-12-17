<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';
require_once __DIR__ . '/external_auth.php';

date_default_timezone_set('America/Fortaleza');
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        error_log('CSRF token mismatch on POST request in external_access.php.');
        http_response_code(403);
        echo "Requisição inválida: Token CSRF ausente ou incorreto.";
        exit;
    }
}

if (empty($_SESSION['auth'])) {
    header('Location: login.php');
    exit;
}

$instances = loadInstancesFromDatabase();
$externalUserMessage = '';
$externalUserError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_external_user'])) {
        $deleteId = (int)($_POST['delete_user_id'] ?? 0);
        if ($deleteId && deleteExternalUser($deleteId)) {
            $externalUserMessage = 'Acesso removido com sucesso.';
        } else {
            $externalUserError = 'Falha ao remover o acesso.';
        }
    } elseif (isset($_POST['update_external_user'])) {
        $updateId = (int)($_POST['update_user_id'] ?? 0);
        $updateRole = ($_POST['update_role'] ?? '') === 'manager' ? 'manager' : 'user';
        $updateStatus = ($_POST['update_status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $updateInstances = array_unique(array_filter((array)($_POST['update_instances'] ?? [])));
        if (!$updateId) {
            $externalUserError = 'Usuário inválido.';
        } elseif (empty($updateInstances)) {
            $externalUserError = 'Selecione pelo menos uma instância.';
        } else {
            $updated = updateExternalUserProfile($updateId, $updateRole, $updateStatus);
            if ($updated) {
                setExternalUserInstances($updateId, $updateInstances);
                $externalUserMessage = 'Acesso atualizado com sucesso.';
            } else {
                $externalUserError = 'Não houve alterações ou o usuário não foi encontrado.';
            }
        }
    } elseif (isset($_POST['create_external_user'])) {
        $createName = trim($_POST['external_name'] ?? '');
        $createEmail = strtolower(trim($_POST['external_email'] ?? ''));
        $createRole = ($_POST['external_role'] ?? '') === 'manager' ? 'manager' : 'user';
        $createInstances = array_unique(array_filter((array)($_POST['external_instances'] ?? [])));
        if ($createName === '' || !filter_var($createEmail, FILTER_VALIDATE_EMAIL)) {
            $externalUserError = 'Informe nome e e-mail válidos.';
        } elseif (empty($createInstances)) {
            $externalUserError = 'Selecione pelo menos uma instância.';
        } else {
            try {
                $password = bin2hex(random_bytes(6));
                createExternalUser($createName, $createEmail, $password, $createRole, $createInstances);
                $instanceLabels = [];
                foreach ($createInstances as $instanceId) {
                    $instanceLabels[] = $instances[$instanceId]['name'] ?? $instanceId;
                }
                sendExternalAccessNotice($createEmail, $createName, $password, $instanceLabels);
                $externalUserMessage = "Acesso {$createEmail} criado e e-mail enviado.";
            } catch (Exception $err) {
                $externalUserError = $err->getMessage();
            }
        }
    }
}

$externalUsersList = listExternalUsers();
foreach ($externalUsersList as &$userRow) {
    $userInstances = getExternalUserInstances((int)($userRow['id'] ?? 0));
    $userRow['instance_ids'] = $userInstances;
    $userRow['instance_names'] = array_map(fn($instanceId) => $instances[$instanceId]['name'] ?? $instanceId, $userInstances);
}
unset($userRow);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acessos externos • Maestro</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; background:#f3f4f6; margin:0; }
    .container { max-width:1200px; margin:0 auto; padding:2rem; }
    h1 { font-size:1.75rem; }
    .card { background:#fff; border:1px solid #e2e8f0; border-radius:1rem; padding:2rem; margin-bottom:1.5rem; box-shadow:0 15px 35px rgba(15,23,42,0.08); }
    .grid-cols-3 { grid-template-columns: repeat(3, minmax(0,1fr)); }
  </style>
</head>
<body>
  <div class="container">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="mb-2 font-semibold text-slate-900">Gerenciar acessos externos</h1>
        <p class="text-sm text-slate-500 mb-6">Crie e edite logins para operadores e gerentes associados às instâncias.</p>
      </div>
      <a href="index.php" class="inline-flex items-center gap-2 rounded-2xl border border-slate-300 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">
        Voltar ao painel principal
      </a>
    </div>
    <div class="card">
      <?php if ($externalUserMessage): ?>
        <p class="text-sm text-green-600 font-semibold mb-2"><?= htmlspecialchars($externalUserMessage) ?></p>
      <?php elseif ($externalUserError): ?>
        <p class="text-sm text-red-500 font-semibold mb-2"><?= htmlspecialchars($externalUserError) ?></p>
      <?php endif; ?>
      <form method="post" class="grid gap-4 lg:grid-cols-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="create_external_user" value="1">
        <div>
          <label class="text-xs text-slate-500">Nome</label>
          <input type="text" name="external_name" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2" required>
        </div>
        <div>
          <label class="text-xs text-slate-500">E-mail</label>
          <input type="email" name="external_email" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2" required>
        </div>
        <div>
          <label class="text-xs text-slate-500">Perfil</label>
          <select name="external_role" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2">
            <option value="user">Usuário</option>
            <option value="manager">Gerente</option>
          </select>
        </div>
        <div class="lg:col-span-3">
          <label class="text-xs text-slate-500">Instâncias autorizadas</label>
          <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($instances as $instanceId => $inst): ?>
              <label class="flex items-center gap-2 text-xs text-slate-600">
                <input type="checkbox" name="external_instances[]" value="<?= htmlspecialchars($instanceId) ?>">
                <?= htmlspecialchars($inst['name'] ?? $instanceId) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="lg:col-span-3">
          <button type="submit" class="w-full rounded-2xl bg-slate-900 text-white px-4 py-2">Criar acesso e enviar e-mail</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2 class="text-lg font-semibold text-slate-900 mb-4">Acessos registrados</h2>
      <div class="space-y-4">
        <?php if (empty($externalUsersList)): ?>
          <p class="text-sm text-slate-500">Nenhum acesso externo registrado.</p>
        <?php else: ?>
          <?php foreach ($externalUsersList as $userRow): ?>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($userRow['name'] ?? '') ?></div>
                  <div class="text-xs text-slate-500"><?= htmlspecialchars($userRow['email'] ?? '') ?></div>
                </div>
                <span class="text-[11px] uppercase <?= ($userRow['status'] ?? '') === 'active' ? 'text-emerald-600' : 'text-red-500' ?>">
                  <?= htmlspecialchars(($userRow['status'] ?? '') === 'active' ? 'Ativo' : 'Inativo') ?>
                </span>
              </div>
              <form method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="update_external_user" value="1">
                <input type="hidden" name="update_user_id" value="<?= (int)($userRow['id'] ?? 0) ?>">
                <div class="flex flex-wrap gap-4 text-xs">
                  <label class="flex items-center gap-2">
                    Perfil
                    <select name="update_role" class="rounded-full border border-slate-300 px-3 py-1 bg-white">
                      <option value="user" <?= ($userRow['role'] ?? '') === 'user' ? 'selected' : '' ?>>Usuário</option>
                      <option value="manager" <?= ($userRow['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Gerente</option>
                    </select>
                  </label>
                  <label class="flex items-center gap-2">
                    Status
                    <select name="update_status" class="rounded-full border border-slate-300 px-3 py-1 bg-white">
                      <option value="active" <?= ($userRow['status'] ?? '') === 'active' ? 'selected' : '' ?>>Ativo</option>
                      <option value="inactive" <?= ($userRow['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                  </label>
                </div>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 text-xs text-slate-600">
                  <?php foreach ($instances as $instanceId => $inst): ?>
                    <label class="flex items-center gap-2">
                      <input type="checkbox" name="update_instances[]" value="<?= htmlspecialchars($instanceId) ?>"
                        <?= in_array($instanceId, $userRow['instance_ids'] ?? [], true) ? 'checked' : '' ?>>
                      <?= htmlspecialchars($inst['name'] ?? $instanceId) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="flex flex-wrap gap-2">
                  <button type="submit" class="rounded-full bg-slate-900 text-white px-4 py-1 text-xs">Salvar</button>
                  <button type="submit" name="delete_external_user" value="1"
                          class="rounded-full border border-red-400 px-4 py-1 text-xs text-red-500 hover:bg-red-50">
                    Excluir acesso
                  </button>
                  <input type="hidden" name="delete_user_id" value="<?= (int)($userRow['id'] ?? 0) ?>">
                </div>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <footer class="w-full bg-slate-900 text-slate-200 text-xs text-center py-3 mt-6">
    Por <strong>Osvaldo J. Filho</strong> |
    <a href="https://linkedin.com/in/ojaneri" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">LinkedIn</a> |
    <a href="https://github.com/ojaneri/maestro" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">GitHub</a>
  </footer>
</body>
</html>
