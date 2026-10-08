<?php
require __DIR__ . '/bootstrap.php';
if ($auth->user()) redirect('/dashboard.php');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($auth->login($email, $password)) redirect('/dashboard.php');
    $error = 'E-mail ou senha inválidos.';
}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar · Sistema de Gestão</title><link rel="stylesheet" href="assets/app.css?v=1"></head>
<body class="login-page"><div class="card login-card">
<div class="login-logo">✦</div><h1>Acessar sistema</h1><p>Entre com seu e-mail e senha para administrar sua igreja.</p>
<?php if ($error): ?><div class="alert alert-error"><?=e($error)?></div><?php endif; ?>
<form method="post" autocomplete="on">
<div class="field"><label for="email">E-mail</label><input class="input" id="email" name="email" type="email" required autofocus value="<?=e($_POST['email'] ?? '')?>"></div>
<div class="field"><label for="password">Senha</label><input class="input" id="password" name="password" type="password" required></div>
<button class="btn btn-primary" type="submit">Entrar</button>
</form>
</div></body></html>
