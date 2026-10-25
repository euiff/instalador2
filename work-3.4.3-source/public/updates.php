<?php
require __DIR__.'/bootstrap.php';

$user=$auth->requireRole('super_admin');
$church=$auth->currentChurch();
if(!$church) exit('Igreja não selecionada.');

require_once __DIR__.'/lib/Updater.php';
$cfg=require __DIR__.'/config/update.php';
$updater=new Updater($pdo,$cfg,__DIR__);

$error=flash('error');
$success=flash('success');
$info=null;

try {
    $info=$updater->check();

    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        if(($_POST['action']??'')==='install'){
            if(empty($info['update_available'])){
                flash('success','O sistema já está atualizado.');
                redirect('/updates.php');
            }
            $result=$updater->install($info);
            flash('success','Atualização instalada: versão '.$result['version'].'. Backup criado em '.$result['backup'].'.');
            redirect('/updates.php');
        }
    }
} catch(Throwable $e){
    $error=$e->getMessage();
}

$history=$pdo->query('SELECT * FROM system_updates ORDER BY installed_at DESC,id DESC LIMIT 20')->fetchAll();

View::header('Atualizações do Sistema',$auth,'updates.php');
?>
<?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

<div class="grid two-col">
<section class="card card-pad">
  <h2>Status</h2>
  <p><strong>Canal:</strong> <?=e($updater->channelLabel())?></p>
  <p><strong>Origem:</strong> <?=e($cfg['channel_url']??$cfg['repository'])?></p>
  <p><strong>Versão instalada:</strong> <?=e($info['current_version']??$updater->currentVersion())?></p>
  <p><strong>Última versão:</strong> <?=e($info['version']??'Indisponível')?></p>
  <?php if(!empty($info['update_available'])):?>
    <div class="alert alert-success">Nova versão disponível.</div>
    <form method="post" onsubmit="return confirm('Instalar atualização agora? Um backup será criado antes da troca dos arquivos.')">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="install">
      <button class="btn btn-primary">Atualizar agora</button>
    </form>
  <?php else:?><div class="alert alert-success">Sistema atualizado.</div><?php endif?>
</section>

<section class="card card-pad">
  <h2>Segurança da atualização</h2>
  <ul>
    <li>Acesso exclusivo do Master/Super Admin.</li>
    <li>Backup automático dos arquivos substituídos.</li>
    <li>Canal público: sem token GitHub, Terminal ou SSH no cPanel.</li>
    <li>Validação SHA-256 do pacote e de cada arquivo publicado.</li>
    <li>Migrations executadas apenas uma vez.</li>
    <li>Credenciais locais e uploads não são sobrescritos.</li>
    <li>Histórico de versões registrado no banco.</li>
  </ul>
</section>
</div>

<section class="card card-pad" style="margin-top:18px">
  <h2>Histórico</h2>
  <div class="table-wrap"><table class="table"><thead><tr><th>Versão</th><th>Instalada em</th><th>Detalhes</th></tr></thead><tbody>
    <?php foreach($history as $h):?><tr><td><?=e($h['version'])?></td><td><?=e(date('d/m/Y H:i:s',strtotime($h['installed_at'])))?></td><td><small><?=e($h['details']?:'—')?></small></td></tr><?php endforeach?>
  </tbody></table></div>
</section>
<?php View::footer(); ?>
