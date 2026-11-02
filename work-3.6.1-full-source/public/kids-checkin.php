<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/KidsService.php';

$token=trim((string)($_GET['t']??''));
$program=$token!==''?KidsService::publicProgram($pdo,$token):null;
$error=null;$success=null;

if(!$program){
    http_response_code(404);
    $error='Programação Kids indisponível ou encerrada.';
}elseif($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        KidsService::requestPublicCheckin($pdo,$program,$_POST);
        $success='Solicitação enviada. A equipe Kids fará a conferência e aprovação antes do check-in.';
    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$churchName=$program['church_name']??'Igreja';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Check-in Kids · <?=e($churchName)?></title>
<style>
:root{font-family:Inter,Arial,sans-serif;color:#142235;background:#f5f8fb}*{box-sizing:border-box}body{margin:0;padding:24px}.shell{max-width:620px;margin:4vh auto}.card{background:white;border:1px solid #dfe7ef;border-radius:20px;padding:24px;box-shadow:0 16px 42px rgba(31,70,110,.08)}h1{margin:0 0 8px;font-size:28px}h2{font-size:18px;margin:22px 0 8px}.muted{color:#66778a;line-height:1.55}.info{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:18px 0}.info div{background:#f6f9fc;border-radius:12px;padding:12px}.info small{display:block;color:#728297}.field{margin:13px 0}.field label{display:block;font-weight:700;font-size:13px;margin-bottom:6px}.input,.textarea{width:100%;padding:12px 13px;border:1px solid #cdd9e5;border-radius:11px;font:inherit}.textarea{min-height:90px;resize:vertical}.btn{width:100%;border:0;border-radius:12px;padding:13px 16px;font-weight:800;background:#266ba8;color:white;cursor:pointer}.alert{padding:12px 14px;border-radius:12px;margin:14px 0;line-height:1.5}.ok{background:#ecf9f0;color:#17623a}.err{background:#fff0f0;color:#922}.security{margin-top:18px;padding:13px;background:#fffaf0;border:1px solid #f0dfb5;border-radius:12px;color:#6c5720}.brand{text-transform:uppercase;letter-spacing:.08em;font-size:11px;color:#6f8295;font-weight:800;margin-bottom:8px}@media(max-width:560px){body{padding:14px}.card{padding:18px}.info{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="shell">
  <div class="card">
    <div class="brand"><?=e($churchName)?> · Kids</div>
    <?php if($program):?>
      <h1>Solicitar check-in</h1>
      <p class="muted">Preencha os dados abaixo. A equipe confere a solicitação antes de liberar a entrada da criança.</p>
      <div class="info">
        <div><small>Programação</small><strong><?=e($program['title'])?></strong></div>
        <div><small>Turma / Sala</small><strong><?=e($program['class_name'])?> · <?=e($program['room_name'])?></strong></div>
        <div><small>Data</small><strong><?=date('d/m/Y',strtotime($program['program_date']))?></strong></div>
        <div><small>Horário</small><strong><?=e($program['start_time']?substr((string)$program['start_time'],0,5):'A definir')?></strong></div>
      </div>
    <?php else:?>
      <h1>Check-in indisponível</h1>
    <?php endif?>

    <?php if($success):?><div class="alert ok"><?=e($success)?></div><?php endif?>
    <?php if($error):?><div class="alert err"><?=e($error)?></div><?php endif?>

    <?php if($program&&!$success):?>
      <form method="post">
        <?=csrf_field()?>
        <div class="field"><label>Nome da criança *</label><input class="input" name="child_name" required maxlength="190"></div>
        <div class="field"><label>Data de nascimento</label><input class="input" type="date" name="birth_date"><small class="muted">Ajuda a localizar um cadastro já existente.</small></div>
        <h2>Responsável</h2>
        <div class="field"><label>Nome do responsável *</label><input class="input" name="guardian_name" required maxlength="190"></div>
        <div class="field"><label>Telefone / WhatsApp *</label><input class="input" name="guardian_phone" required maxlength="60" inputmode="tel"></div>
        <div class="field"><label>Observação</label><textarea class="textarea" name="notes" maxlength="1000" placeholder="Alguma informação que a equipe precisa saber agora?"></textarea></div>
        <button class="btn">Enviar solicitação</button>
      </form>
      <div class="security"><strong>Segurança:</strong> a solicitação não faz a entrada automaticamente. A equipe Kids precisa aprovar e entregar o código de retirada.</div>
    <?php endif?>
  </div>
</div>
</body>
</html>