<?php
require __DIR__.'/bootstrap.php';
$auth->requireLogin();

if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);
    exit('Método não permitido.');
}

verify_csrf();
$id=(string)($_POST['church_id']??'');
if($id===''||!$auth->selectChurch($id)){
    flash('error','Você não possui acesso a esta igreja.');
    redirect('/dashboard.php');
}

$back=(string)($_POST['back']??'/dashboard.php');
if(!str_starts_with($back,'/')||str_starts_with($back,'//'))$back='/dashboard.php';
redirect($back);
