<?php
declare(strict_types=1);

function role_label(string $r): string {
    return [
        'master'=>'MASTER',
        'pastor'=>'Pastor / Administrador',
        'secretaria'=>'Secretaria',
        'tesouraria'=>'Tesouraria'
    ][$r] ?? $r;
}

function nav_icon(string $route): string {
    $icons = [
        'master'=>'<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/>',
        'updates'=>'<path d="M20 6v6h-6"/><path d="M4 18v-6h6"/><path d="M18.5 9A7 7 0 0 0 6.3 6.3L4 12"/><path d="M5.5 15A7 7 0 0 0 17.7 17.7L20 12"/>',
        'dashboard'=>'<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
        'members'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'families'=>'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
        'visitors'=>'<path d="M15 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'events'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'documents'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
        'finance'=>'<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"/>',
        'payables'=>'<path d="M6 2h12l2 4v16H4V6z"/><path d="M4 6h16M8 11h8M8 15h6"/>',
        'reports'=>'<path d="M3 3v18h18"/><path d="M7 16l4-5 3 3 5-7"/>',
        'church-settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1-.4H3v-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1V3h4v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.12.36.33.69.6 1 .27.31.62.52 1 .6H21v4h-.09a1.7 1.7 0 0 0-1.51.4z"/>',
        'users'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>'
    ];
    $path = $icons[$route] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

function nav_item(string $r,string $label): string {
    $active=(($_GET['r']??'dashboard')===$r)?'active':'';
    return '<a class="nav '.$active.'" data-route="'.e($r).'" href="/?r='.e($r).'"><span class="nav-icon">'.nav_icon($r).'</span><span class="nav-label">'.e($label).'</span></a>';
}

function page_top(string $title,string $subtitle=''): void {
    $u=user();
    $churchName='AdminIgreja';
    if(church_id() && function_exists('one')) {
        $c=one('SELECT name FROM churches WHERE id=?',[church_id()]);
        if($c) $churchName=$c['name'];
    }

    $currentRoute=(string)($_GET['r']??'dashboard');
    $routeClass=preg_replace('/[^a-z0-9-]/','-',strtolower($currentRoute));
    $initial=trim((string)($u['name']??''));
    $initial=$initial!==''?strtoupper(substr($initial,0,1)):'U';

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#102a43"><title>'.e($title).' - AdminIgreja</title><link rel="stylesheet" href="/assets/app.css"></head><body class="app-body route-'.e($routeClass).'">';
    echo '<div class="mobile-backdrop" id="mobileBackdrop" ></div><div class="shell">';

    echo '<aside id="appSidebar">';
    echo '<div class="sidebar-head"><a class="brand" href="/?r='.(is_master()?'master':'dashboard').'"><span class="brand-mark">AI</span><span class="brand-copy"><b>'.e(is_master()?'AdminIgreja':$churchName).'</b><small>'.e(is_master()?'Painel MASTER':'Gestão eclesiástica').'</small></span></a><button class="sidebar-close" id="menuClose" type="button" aria-label="Fechar menu" >×</button></div>';
    echo '<div class="sidebar-scroll">';

    if(is_master()) {
        echo '<div class="section"><span>MASTER</span></div>';
        echo nav_item('master','Painel Master').nav_item('updates','Atualizações');
        if(church_id()) echo nav_item('dashboard','Entrar na igreja');
    }

    if(church_id()) {
        echo '<div class="section"><span>VISÃO GERAL</span></div>'.nav_item('dashboard','Painel');

        if(can_secretary()) {
            echo '<div class="section"><span>SECRETARIA</span></div>';
            echo nav_item('members','Membros').nav_item('families','Famílias').nav_item('visitors','Visitantes').nav_item('events','Cultos / Eventos').nav_item('documents','Documentos');
        }

        if(can_treasury()) {
            echo '<div class="section"><span>TESOURARIA</span></div>';
            echo nav_item('finance','Movimentações').nav_item('payables','Contas a pagar').nav_item('reports','Relatórios');
        }

        if(can_manage_users()) {
            echo '<div class="section"><span>ADMINISTRAÇÃO</span></div>';
            echo nav_item('church-settings','Dados da igreja').nav_item('users','Usuários e permissões');
        }
    }

    echo '</div>';
    echo '<div class="userbox"><div class="user-avatar">'.e($initial).'</div><div class="user-meta"><b>'.e($u['name']??'').'</b><small>'.e(role_label((string)($u['role']??''))).'</small></div><a class="logout-link" href="/?r=logout" title="Sair" aria-label="Sair"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg></a></div>';
    echo '</aside>';

    echo '<main><header class="topbar"><div class="topbar-left"><button class="menu-toggle" type="button" aria-label="Abrir menu" id="menuOpen"><span></span><span></span><span></span></button><div class="page-heading"><div class="page-kicker">'.e(is_master()?'ADMINISTRAÇÃO MASTER':'ADMINIGREJA').'</div><h1>'.e($title).'</h1><p>'.e($subtitle).'</p></div></div><div class="topbar-actions"><span class="role-chip"><span class="role-dot"></span>'.e(role_label((string)($u['role']??''))).'</span></div></header>';
    echo '<section class="content"><div class="content-inner">';

    if(church_id() && can_secretary() && function_exists('all')) {
        $noticeKey='birthday_notice_'.church_id().'_'.date('Y-m-d');
        if(empty($_SESSION[$noticeKey])) {
            $today=date('Y-m-d');
            $birthdays=all("SELECT id,name,birth_date,TIMESTAMPDIFF(YEAR,birth_date,?) age FROM members WHERE church_id=? AND status='active' AND birth_date IS NOT NULL AND DATE_FORMAT(birth_date,'%m-%d')=? ORDER BY name",[$today,church_id(),date('m-d')]);
            $_SESSION[$noticeKey]=1;
            if($birthdays) {
                $items=[];
                foreach($birthdays as $b){
                    $age=(int)($b['age']??0);
                    $items[]=e((string)$b['name']).($age>0?' <span>'.e((string)$age).' anos</span>':'');
                }
                echo '<div class="birthday-toast" id="birthdayToast"><button type="button" class="birthday-close" id="birthdayClose">×</button><div class="birthday-icon">🎂</div><div class="birthday-copy"><b>Aniversariante'.(count($birthdays)>1?'s':'').' de hoje</b><p>'.implode(' · ',$items).'</p><small>Uma ótima oportunidade para demonstrar cuidado e proximidade.</small></div></div>';
            }
        }
    }
}

function page_bottom(): void {
    echo '</div></section></main></div>';
    echo '<script>(function(){const body=document.body;const open=document.getElementById("menuOpen"),close=document.getElementById("menuClose"),back=document.getElementById("mobileBackdrop"),bclose=document.getElementById("birthdayClose");if(open)open.addEventListener("click",()=>body.classList.add("nav-open"));if(close)close.addEventListener("click",()=>body.classList.remove("nav-open"));if(back)back.addEventListener("click",()=>body.classList.remove("nav-open"));if(bclose)bclose.addEventListener("click",()=>{const t=document.getElementById("birthdayToast");if(t)t.remove()});const links=document.querySelectorAll("#appSidebar .nav");links.forEach(a=>a.addEventListener("click",()=>body.classList.remove("nav-open")));document.addEventListener("keydown",e=>{if(e.key==="Escape")body.classList.remove("nav-open")});const toast=document.getElementById("birthdayToast");if(toast){setTimeout(()=>{toast.classList.add("birthday-soft")},9000)}})();</script>';
    echo '</body></html>';
}

function flash(): void {
    if(!empty($_SESSION['flash'])) {
        $f=$_SESSION['flash'];
        unset($_SESSION['flash']);
        $type=(string)($f['type']??'ok');
        $icon=$type==='error'?'!':'✓';
        echo '<div class="alert '.e($type).'"><span class="alert-icon">'.e($icon).'</span><span>'.e($f['msg']).'</span></div>';
    }
}

function set_flash(string $msg,string $type='ok'): void {
    $_SESSION['flash']=['msg'=>$msg,'type'=>$type];
}
