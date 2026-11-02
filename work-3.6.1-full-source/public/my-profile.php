<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/MemberSelfService.php';

$user=$auth->requireAbility('member_portal');
$church=$auth->currentChurch();
if(!$church)redirect($auth->isSuperAdmin()?'/master.php':'/logout.php');

$service=new MemberSelfService($pdo,$auth,(string)$church['id']);
$error=null;$success=flash('success');

try{$member=$service->member();}
catch(Throwable $e){$member=null;$error=$e->getMessage();}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf();
        $service->update($_POST);
        flash('success','Seus dados de contato e endereço foram atualizados.');
        redirect('/my-profile.php');
    }catch(Throwable $e){
        $error=$e instanceof PDOException?'Não foi possível salvar seus dados. Tente novamente.':$e->getMessage();
    }
}

if($member){
    try{$member=$service->member();}catch(Throwable){}
}
$completeness=$member?$service->completeness($member):0;

View::header('Meu Cadastro',$auth,'my-profile.php');
View::flash();
?>
<style>
.profile-shell{max-width:940px;margin:0 auto}.profile-hero{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:16px}.profile-hero h1{margin:0 0 5px}.profile-hero p{margin:0;color:var(--muted)}
.profile-progress{min-width:210px}.profile-progress strong{display:block;font-size:22px;text-align:right}.progress-track{height:9px;border-radius:999px;background:#e8edf2;overflow:hidden;margin-top:6px}.progress-track span{display:block;height:100%;background:#3178b7}
.profile-note{padding:12px 14px;border-radius:12px;background:#f7f9fc;color:#607184;margin-bottom:16px;line-height:1.5}.profile-readonly{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}.profile-readonly .card{padding:12px}.profile-readonly small{display:block;color:var(--muted);font-size:10px}.profile-readonly strong{display:block;margin-top:4px}
@media(max-width:720px){.profile-hero{flex-direction:column;align-items:stretch}.profile-progress strong{text-align:left}.profile-readonly{grid-template-columns:1fr}}
</style>

<div class="profile-shell">
  <div class="profile-hero">
    <div><h1>Meu Cadastro</h1><p>Atualize seus dados pessoais de contato e endereço.</p></div>
    <?php if($member):?><div class="profile-progress"><strong><?=$completeness?>%</strong><small class="muted">cadastro de contato preenchido</small><div class="progress-track"><span style="width:<?=max(0,min(100,$completeness))?>%"></span></div></div><?php endif?>
  </div>

  <?php if($success):?><div class="alert alert-success"><?=e($success)?></div><?php endif?>
  <?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif?>

  <?php if($member):?>
    <div class="profile-readonly">
      <div class="card"><small>Nome</small><strong><?=e($member['name'])?></strong></div>
      <div class="card"><small>Congregação</small><strong><?=e($member['congregation_name']?:'Igreja geral')?></strong></div>
      <div class="card"><small>Situação</small><strong><?=e($member['registration_status']?:'Ativo')?></strong></div>
    </div>

    <div class="profile-note">
      Alguns dados são controlados pela secretaria e não podem ser alterados por aqui, como cargo, situação de membro, batismo, CPF/RG e datas de membresia. Para corrigir esses dados, procure a secretaria.
    </div>

    <section class="card card-pad">
      <form method="post">
        <?=csrf_field()?>
        <div class="form-grid">
          <label>Telefone / WhatsApp *<input class="input" name="phone" required maxlength="40" value="<?=e($member['phone']??'')?>"></label>
          <label>E-mail de contato<input class="input" type="email" name="email" value="<?=e($member['email']??'')?>"><small class="muted">Alterar aqui não muda o e-mail usado para entrar no sistema.</small></label>
          <label>Data de nascimento<input class="input" type="date" name="birth_date" value="<?=e($member['birth_date']??'')?>"></label>
          <label>Naturalidade<input class="input" name="naturalness" maxlength="120" value="<?=e($member['naturalness']??'')?>"></label>
          <label>Estado civil<input class="input" name="marital_status" maxlength="80" value="<?=e($member['marital_status']??'')?>"></label>
          <label>Cônjuge<input class="input" name="spouse_name" maxlength="255" value="<?=e($member['spouse_name']??'')?>"></label>

          <div class="full" style="border-top:1px solid var(--line);padding-top:12px"><strong>Endereço</strong></div>
          <label>CEP<input class="input" name="cep" maxlength="20" value="<?=e($member['cep']??'')?>"></label>
          <label>Rua / Avenida<input class="input" name="address_street" maxlength="255" value="<?=e($member['address_street']??'')?>"></label>
          <label>Número<input class="input" name="address_number" maxlength="50" value="<?=e($member['address_number']??'')?>"></label>
          <label>Bairro<input class="input" name="address_neighborhood" maxlength="255" value="<?=e($member['address_neighborhood']??'')?>"></label>
          <label>Cidade<input class="input" name="address_city" maxlength="255" value="<?=e($member['address_city']??'')?>"></label>
          <label>UF / Estado<input class="input" name="address_state" maxlength="80" value="<?=e($member['address_state']??'')?>"></label>
        </div>
        <button class="btn btn-primary">Salvar meus dados</button>
      </form>
    </section>
  <?php endif?>
</div>
<?php View::footer(); ?>