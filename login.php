<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/external_auth.php';

if (!function_exists('debug_log')) {
    if (file_exists('debug')) {
        function debug_log($message) {
            file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    } else {
        function debug_log($message) { }
    }
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
try {
    $dotenv->load();
    debug_log('login.php: dotenv loaded');
} catch (Exception $e) {
    debug_log('login.php: dotenv load failed: ' . $e->getMessage());
}

ensureExternalUsersSchema();

session_start();
debug_log('login.php: session started');

$validEmail = strtolower(trim($_ENV['PANEL_USER_EMAIL'] ?? ''));
$validPass  = $_ENV['PANEL_PASSWORD'] ?? '';

$errorMessage = null;

if (isset($_SESSION['auth']) || isset($_SESSION['external_user'])) {
    $redirectTo = '/api/envio/wpp/';
    if (isset($_SESSION['external_user']) && ($_SESSION['external_user']['role'] ?? '') === 'user') {
        $redirectTo = '/api/envio/wpp/external_dashboard.php';
    }
    header("Location: {$redirectTo}");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    if ($email === '' || $password === '') {
        $errorMessage = 'Informe e-mail e senha.';
    } elseif ($validEmail !== '' && $email === $validEmail && $password === $validPass) {
        $_SESSION['auth'] = true;
        debug_log('login.php: admin login success');
        header('Location: /api/envio/wpp/');
        exit;
    } else {
        $user = getExternalUserByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errorMessage = 'E-mail ou senha inválidos.';
            debug_log("login.php: external login failed for {$email}");
        } elseif ($user['status'] !== 'active') {
            $errorMessage = 'Acesso bloqueado. Contate o administrador.';
            debug_log("login.php: external login blocked for {$email}");
        } else {
            $instances = getExternalUserInstances((int)$user['id']);
            if (empty($instances)) {
                $errorMessage = 'Nenhuma instância atribuída.';
                debug_log("login.php: user {$email} has no instances");
            } else {
                $_SESSION['external_user'] = [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'instances' => array_map(fn($instanceId) => ['instance_id' => $instanceId], $instances)
                ];
                debug_log("login.php: external login success for {$email} role {$user['role']}");
                if ($user['role'] === 'manager') {
                    header("Location: /api/envio/wpp/");
                } else {
                    header("Location: /api/envio/wpp/external_dashboard.php");
                }
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Login – Painel WhatsApp</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: dark;
            --bg: #020617;
            --accent: #22c55e;
            --border: #1e293b;
            --text: #e5e7eb;
            --text-muted: #9ca3af;
            --danger: #ef4444;
        }
        body {
            margin:0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #1e293b 0, #020617 45%, #000 100%);
            color: var(--text);
            display:flex;
            align-items:center;
            justify-content:center;
            min-height:100vh;
        }
        .card {
            background: rgba(15,23,42,0.95);
            border-radius: 12px;
            border:1px solid var(--border);
            padding: 20px 22px;
            width: 100%;
            max-width: 360px;
        }
        h1 {
            margin:0 0 4px;
            font-size: 20px;
        }
        .sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 14px;
        }
        label {
            font-size: 12px;
            display:block;
            margin-bottom:4px;
            color: var(--text-muted);
        }
        input[type="email"], input[type="password"] {
            width:100%;
            padding:8px 10px;
            border-radius: 8px;
            border:1px solid var(--border);
            background:#020617;
            color:var(--text);
            font-size:13px;
            margin-bottom:10px;
        }
        input:focus {
            outline:none;
            border-color: var(--accent);
        }
        button {
            width:100%;
            padding:9px 10px;
            border-radius: 999px;
            border:1px solid var(--accent);
            background: rgba(34,197,94,0.2);
            color: var(--accent);
            font-size:13px;
            cursor:pointer;
        }
        button:hover {
            background: rgba(34,197,94,0.35);
        }
        .error {
            background: rgba(239,68,68,0.12);
            border:1px solid var(--danger);
            color: var(--danger);
            padding:6px 8px;
            border-radius:8px;
            font-size:12px;
            margin-bottom:10px;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>Painel WhatsApp</h1>
    <div class="sub">Use o mesmo formulário para administradores, gerentes ou operadores autorizados.</div>

    <?php if ($errorMessage): ?>
        <div class="error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required autofocus>

        <label for="password">Senha</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Entrar</button>
    </form>
</div>
</body>
</html>
