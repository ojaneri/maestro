<?php
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists('debug')) {
    if (!function_exists('debug_log')) {
        function debug_log($message) {
            file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
} else {
    if (!function_exists('debug_log')) {
        function debug_log($message) { }
    }
}
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
try {
    $dotenv->load();
    debug_log('Dotenv load successful');
} catch (Exception $e) {
    debug_log('Dotenv load failed: ' . $e->getMessage());
}
debug_log('PANEL_USER_EMAIL from _ENV: ' . ($_ENV['PANEL_USER_EMAIL'] ?? 'not set'));
debug_log('PANEL_PASSWORD from _ENV: ' . ($_ENV['PANEL_PASSWORD'] ?? 'not set'));
debug_log('PANEL_USER_EMAIL from getenv: ' . (getenv('PANEL_USER_EMAIL') ?: 'not set'));
debug_log('PANEL_PASSWORD from getenv: ' . (getenv('PANEL_PASSWORD') ?: 'not set'));
 
session_start();
debug_log('login.php: Session started. auth: ' . (isset($_SESSION['auth']) ? 'true' : 'false'));

$validEmail = $_ENV['PANEL_USER_EMAIL'] ?? '';
$validPass  = $_ENV['PANEL_PASSWORD'] ?? '';

$error = null;

if (isset($_SESSION['auth'])) {
    debug_log('User already logged in with auth=true, redirecting to /api/envio/wpp/');
    header('Location: /api/envio/wpp/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    debug_log('Login POST attempt with email: ' . $email);

    if ($email === $validEmail && $pass === $validPass) {
        debug_log('Login successful, setting session auth=true, redirecting to /api/envio/wpp/');
        $_SESSION['auth'] = true;
        header('Location: /api/envio/wpp/');
        exit;
    } else {
        $error = 'Login ou senha inválidos.';
        debug_log('Login failed: invalid credentials, setting error: ' . $error);
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
    <div class="sub">Acesse com seu e-mail e senha configurados no .env</div>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required value="">

        <label for="password">Senha</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Entrar</button>
    </form>
</div>
</body>
</html>

