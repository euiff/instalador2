<?php
require __DIR__ . '/bootstrap.php';
if ($auth->user()) redirect($auth->isSuperAdmin()?'/master.php':'/dashboard.php');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($auth->login($email, $password)) redirect($auth->isSuperAdmin()?'/master.php':'/dashboard.php');
    $error = 'E-mail ou senha inválidos.';
}
?><!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Entrar · Igreja Master</title>
<link rel="stylesheet" href="/assets/app.css?v=9">
<link rel="stylesheet" href="/assets/admin-ui.css?v=340">
<link rel="stylesheet" href="/assets/login-real-3729.css?v=3.7.29">
</head>
<body class="im-login-page">
<div class="im-login-layout">
 <aside class="im-login-about" aria-label="Sobre o Igreja Master">
  <div class="im-login-symbol" aria-hidden="true">✝</div>
  <h1>Igreja Master</h1>
  <p>Um sistema mais simples para cuidar da sua igreja.</p>
  <div class="im-login-feature">Secretaria, Tesouraria, membros, departamentos, documentos e relatórios no mesmo lugar.</div>
 </aside>
 <main class="im-login-card">
  <h2>Entrar na sua igreja</h2>
  <p>Use o seu acesso para abrir as funções e a identidade visual da sua igreja.</p>
  <?php if ($error):?><div class="alert-error" role="alert"><?=e($error)?></div><?php endif;?>
  <form method="post" autocomplete="on">
   <div class="field"><label for="email">E-mail</label>
    <input class="input" id="email" name="email" type="email" autocomplete="username"
     placeholder="seuemail@exemplo.com" value="<?=e($_POST['email']??'')?>" required autofocus></div>
   <div class="field"><label for="password">Senha</label>
    <div class="im-password-wrap"><input class="input" id="password" name="password"
      type="password" autocomplete="current-password" required>
      <button id="im-show-password" type="button" class="im-password-toggle"
       aria-controls="password" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button></div></div>
   <button type="submit">Entrar</button>
  </form>
  <p class="im-login-note">A logo e as cores da sua igreja serão carregadas após identificar seu acesso.</p>
 </main>
</div>
<script>
(function(){
 var button=document.getElementById('im-show-password'),field=document.getElementById('password');
 if(!button||!field)return;
 button.addEventListener('click',function(){
   var visible=field.type==='password';
   field.type=visible?'text':'password';
   button.textContent=visible?'Ocultar':'Mostrar';
   button.setAttribute('aria-label',visible?'Ocultar senha':'Mostrar senha');
   button.setAttribute('aria-pressed',String(visible));
 });
})();
</script>
</body></html>
