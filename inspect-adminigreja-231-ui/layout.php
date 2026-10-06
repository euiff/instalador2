<?php
declare(strict_types=1);
function role_label(string $r): string { return ['master'=>'MASTER','pastor'=>'Pastor / Administrador','secretaria'=>'Secretaria','tesouraria'=>'Tesouraria'][$r]??$r; }
function nav_item(string $r,string $label): string { $active=(($_GET['r']??'dashboard')===$r)?'active':''; return '<a class="nav '.$active.'" href="/?r='.e($r).'">'.e($label).'</a>'; }
function page_top(string $title,string $subtitle=''): void {
    $u=user(); $churchName='AdminIgreja';
    if(church_id() && function_exists('one')) { $c=one('SELECT name FROM churches WHERE id=?',[church_id()]); if($c) $churchName=$c['name']; }
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' - AdminIgreja</title><link rel="stylesheet" href="/assets/app.css"></head><body><div class="shell"><aside><div class="brand"><b>'.e(is_master()?'AdminIgreja MASTER':$churchName).'</b><small>Gestão administrativa</small></div>';
    if(is_master()) { echo '<div class="section">MASTER</div>'.nav_item('master','Painel Master').nav_item('updates','Atualizações'); if(church_id()) echo nav_item('dashboard','Entrar na igreja'); }
    if(church_id()) {
      echo '<div class="section">VISÃO GERAL</div>'.nav_item('dashboard','Painel');
      if(can_secretary()) echo '<div class="section">SECRETARIA</div>'.nav_item('members','Membros').nav_item('families','Famílias').nav_item('visitors','Visitantes').nav_item('events','Cultos / Eventos').nav_item('documents','Gerar documentos');
      if(can_treasury()) echo '<div class="section">TESOURARIA</div>'.nav_item('finance','Movimentações').nav_item('payables','Contas a pagar').nav_item('reports','Relatórios');
      if(can_manage_users()) echo '<div class="section">ADMINISTRAÇÃO</div>'.nav_item('church-settings','Dados da igreja').nav_item('users','Usuários e permissões');
    }
    echo '<div class="userbox"><b>'.e($u['name']??'').'</b><small>'.e(role_label((string)($u['role']??''))).'</small><a href="/?r=logout">Sair</a></div></aside><main><header><div><h1>'.e($title).'</h1><p>'.e($subtitle).'</p></div></header><section class="content">';
    if(church_id() && can_secretary() && function_exists('all')) {
        $noticeKey='birthday_notice_'.church_id().'_'.date('Y-m-d');
        if(empty($_SESSION[$noticeKey])) {
            $today=date('Y-m-d');$birthdays=all("SELECT id,name,birth_date,TIMESTAMPDIFF(YEAR,birth_date,?) age FROM members WHERE church_id=? AND status='active' AND birth_date IS NOT NULL AND DATE_FORMAT(birth_date,'%m-%d')=? ORDER BY name",[$today,church_id(),date('m-d')]);
            $_SESSION[$noticeKey]=1;
            if($birthdays) {
                $items=[]; foreach($birthdays as $b){$age=(int)($b['age']??0);$items[]=e((string)$b['name']).($age>0?' <span>'.e((string)$age).' anos</span>':'');}
                echo '<div class="birthday-toast" id="birthdayToast"><button type="button" class="birthday-close" onclick="document.getElementById(\'birthdayToast\').remove()">×</button><div class="birthday-icon">🎂</div><div><b>Aniversariante'.(count($birthdays)>1?'s':'').' de hoje</b><p>'.implode(' · ',$items).'</p><small>Que seja um dia abençoado!</small></div></div>';
            }
        }
    }
}
function page_bottom(): void { echo '</section></main></div></body></html>'; }
function flash(): void { if(!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="alert '.e($f['type']).'">'.e($f['msg']).'</div>'; } }
function set_flash(string $msg,string $type='ok'): void { $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
