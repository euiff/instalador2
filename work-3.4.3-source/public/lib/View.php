<?php

declare(strict_types=1);

require_once __DIR__.'/Ui.php';

final class View
{
    /**
     * Aceita as duas formas usadas durante a migração:
     * View::header('Dashboard', $auth, 'dashboard.php')
     * View::header('Dashboard', 'dashboard', $auth, $church)
     */
    public static function header(string $title, mixed $arg2, mixed $arg3 = '', mixed $arg4 = null): void
    {
        if ($arg2 instanceof Auth) {
            $auth = $arg2;
            $active = is_string($arg3) ? $arg3 : '';
        } elseif ($arg3 instanceof Auth) {
            $active = is_string($arg2) ? $arg2 : '';
            $auth = $arg3;
        } else {
            throw new InvalidArgumentException('Auth inválido no layout.');
        }

        $user = $auth->requireLogin();
        $church = is_array($arg4) ? $arg4 : $auth->currentChurch();
        $churches = $auth->churches();
        $isSuperAdmin = $auth->isSuperAdmin((string)$user['id']);
        $isMasterSupport = $auth->isMasterSupportMode();
        $items = [
            'dashboard.php' => ['dashboard','Início','⌂','dashboard'],
            'finance.php' => ['finance','Tesouraria','◈','finance'],
            'reports.php' => ['reports','Relatórios','▥','reports'],
            'needs.php' => ['needs','Necessidades','!','needs'],
            'members.php' => ['members','Membros','♙','secretary'],
            'visitors.php' => ['visitors','Visitantes e acolhimento','♡','secretary'],
            'pastoral-care.php' => ['pastoral-care','Cuidado pastoral','♡','pastoral'],
            'departments.php' => ['departments','Departamentos','♙','secretary'],
            'church-insights.php' => ['church-insights','Pessoas e crescimento','▥','secretary'],
            'assets.php' => ['assets','Patrimônio','▣','finance'],
            'documents.php' => ['documents','Documentos','▤','secretary'],
            'congregations.php' => ['congregations','Congregações','▣','secretary'],
            'events.php' => ['events','Eventos','□','secretary'],
            'event-registrations.php' => ['event-registrations','Inscrições de Eventos','☑','secretary'],
            'service-types.php' => ['service-types','Cultos e reuniões','♢','secretary'],
            'prayers.php' => ['prayers','Pedidos de Oração','♡','secretary'],
            'groups.php' => ['groups','Grupos da Igreja','☏','communications'],
            'campaigns.php' => ['campaigns','Comunicados','✉','communications'],
            'automations.php' => ['automations','Automações','↻','settings'],
            'messages.php' => ['messages','Histórico WhatsApp','≡','communications'],
            'group-moderation.php' => ['group-moderation','Moderação de Grupos','◇','communications'],
            'polls.php' => ['polls','Enquetes','✓','communications'],
            'bible.php' => ['bible','Bíblia e Leitura','☷','communications'],
            'prayer-clock.php' => ['prayer-clock','Relógio de Oração','◷','communications'],
            'raffles.php' => ['raffles','Rifas','◇','communications'],
            'users.php' => ['users','Usuários e permissões','♧','manage_users'],
            'bot-settings.php' => ['bot-settings','Configuração do Bot','⌘','settings'],
            'logs.php' => ['logs','Logs','≡','settings'],
            'settings.php' => ['settings','Configurações','⚙','settings'],
        ];
        $nav=[];
        foreach($items as $href=>$item){
            if($auth->can($item[3]))$nav[$href]=[$item[0],$item[1],$item[2]];
        }
        if ($isSuperAdmin) {
            $nav['master.php'] = ['master','Painel Master','★'];
            $nav['updates.php'] = ['updates','Atualizações','↻'];
            if(!$isMasterSupport){
                $nav=[
                    'master.php'=>['master','Painel Master','★'],
                    'updates.php'=>['updates','Atualizações','↻'],
                ];
            }
        }
        $groups = [
            'Visão geral'=>['home',['dashboard.php','church-insights.php','reports.php']],
            'Pessoas e cadastros'=>['people',['members.php','visitors.php','pastoral-care.php','departments.php','congregations.php','documents.php','prayers.php']],
            'Agenda da igreja'=>['calendar',['events.php','service-types.php','event-registrations.php']],
            'Financeiro'=>['wallet',['finance.php','assets.php','needs.php']],
            'Comunicação'=>['message',['groups.php','campaigns.php','messages.php','polls.php','prayer-clock.php','raffles.php','bible.php','group-moderation.php']],
            'Administração'=>['settings',['users.php','settings.php','bot-settings.php','automations.php','logs.php','master.php','updates.php']],
        ];
        $pageFile=str_ends_with($active,'.php')?$active:$active.'.php';
        $groupTitle='Igreja Master';
        foreach($groups as $group=>[$icon,$links]) if(in_array($pageFile,$links,true)) $groupTitle=$group;
        $quickCreates=[
            ['members.php','Membro','Nome, contato e dados da igreja','people','secretary'],
            ['visitors.php','Visitante','Acolhimento e próximo contato','heart','secretary'],
            ['congregations.php','Congregação','Unidades e responsáveis','building','secretary'],
            ['departments.php','Departamento','Ministérios e participantes','people','secretary'],
            ['events.php','Evento','Programação e inscrições','calendar','secretary'],
            ['service-types.php','Culto ou reunião','Agenda recorrente da igreja','calendar','secretary'],
            ['event-registrations.php','Inscrição em evento','Registrar um participante','calendar','secretary'],
            ['finance.php','Lançamento financeiro','Entrada ou saída no caixa','wallet','finance'],
            ['assets.php','Bem patrimonial','Inventário e manutenção','building','finance'],
            ['pastoral-care.php','Acompanhamento pastoral','Visitas e retornos','heart','pastoral'],
            ['groups.php','Grupo de WhatsApp','Destinos dos comunicados','message','communications'],
            ['campaigns.php','Comunicado','Mensagem para os grupos','message','communications'],
        ];
        ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?=e($title)?> · <?=e($church['name'] ?? 'Igreja Master')?></title>
<link rel="stylesheet" href="/assets/app.css?v=9">
<link rel="stylesheet" href="/assets/admin-ui.css?v=340">
<script src="/assets/admin-ui.js?v=340" defer></script>
</head>
<body data-app-page="<?=e($pageFile)?>">
<a class="skip-link" href="#main-content">Ir para o conteúdo</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Menu principal">
        <a class="brand" href="<?=$isSuperAdmin&&!$isMasterSupport?'/master.php':'/dashboard.php'?>"><div class="brand-logo"><?php if(!$isSuperAdmin&& !empty($church['logo_url'])):?><img src="<?=e($church['logo_url'])?>" alt=""><?php elseif($isMasterSupport&& !empty($church['logo_url'])):?><img src="<?=e($church['logo_url'])?>" alt=""><?php else:?>✦<?php endif?></div><div><strong>Igreja Master</strong><small><?=$isSuperAdmin?($isMasterSupport?'Suporte à igreja-cliente':'Plataforma SaaS'):'Gestão da sua igreja'?></small></div></a>
        <button class="sidebar-close icon-button" type="button" data-sidebar-close aria-label="Fechar menu"><?=Ui::icon('close')?></button>
        <?php if($isSuperAdmin&&!$isMasterSupport):?>
        <div class="current-church"><?=Ui::icon('building')?><span>Plataforma Master</span></div>
        <?php elseif($isSuperAdmin&&$isMasterSupport):?>
        <div class="current-church" style="display:block"><div style="font-size:10px;text-transform:uppercase;opacity:.7;margin-bottom:3px">Modo suporte</div><span><?=e($church['name']??'Igreja selecionada')?></span></div>
        <form method="post" action="/switch-church.php" style="margin:8px 12px 12px"><?=csrf_field()?><input type="hidden" name="action" value="leave_master_support"><button class="btn btn-light" type="submit" style="width:100%">Voltar ao Master</button></form>
        <?php elseif(count($churches)>1):?>
        <form class="church-switch" method="post" action="/switch-church.php"><?=csrf_field()?><label for="church_id">Igreja em uso</label><select id="church_id" name="church_id" onchange="this.form.submit()"><?php foreach($churches as $c):?><option value="<?=e($c['id'])?>" <?=$church&&$church['id']===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach?></select></form>
        <?php else:?><div class="current-church"><?=Ui::icon('building')?><span><?=e($church['name']??'Sua igreja')?></span></div><?php endif?>
        <button class="nav-search" type="button" data-open-dialog="menu-search"><?=Ui::icon('search')?><span>Encontrar uma função</span><kbd>/</kbd></button>
        <nav class="nav-list" aria-label="Áreas do sistema">
        <?php foreach($groups as $group=>[$icon,$links]):
            $available=array_filter($links,fn($href)=>isset($nav[$href])); if(!$available)continue;
            $isGroupActive=in_array($pageFile,$links,true);?>
            <details class="nav-group" <?=$isGroupActive||$group==='Visão geral'?'open':''?>>
                <summary><?=Ui::icon($icon)?><span><?=e($group)?></span><span class="nav-chevron" aria-hidden="true">⌄</span></summary>
                <div class="nav-group-links"><?php foreach($available as $href):[$key,$label]=$nav[$href];$isActive=$pageFile===$href;?>
                <a class="nav-item <?=$isActive?'active':''?>" href="/<?=e($href)?>" <?=$isActive?'aria-current="page"':''?>><?=e($label)?></a>
                <?php endforeach?></div>
            </details>
        <?php endforeach?>
        </nav>
        <div class="sidebar-user"><div class="avatar"><?=e(mb_strtoupper(mb_substr($user['full_name']?:$user['email'],0,1)))?></div><div class="sidebar-user-text"><strong><?=e($user['full_name']?:'Minha conta')?></strong><small><?=e($user['email'])?></small></div><a class="logout icon-button" href="/logout.php" aria-label="Sair da conta">↪</a></div>
    </aside>
    <div class="page-wrap">
        <header class="app-topbar"><button type="button" class="menu-button icon-button" data-sidebar-open aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false"><?=Ui::icon('menu')?></button><div class="breadcrumb"><span><?=e($groupTitle)?></span><strong><?=e($title==='Dashboard'?'Início':$title)?></strong></div><div class="topbar-actions"><button class="topbar-search icon-button" type="button" data-open-dialog="menu-search" aria-label="Buscar no menu"><?=Ui::icon('search')?></button><?php if(!($isSuperAdmin&&!$isMasterSupport)):?><?=Ui::newButton('quick-create','Novo cadastro')?><?php endif?></div></header>
        <div class="sidebar-overlay" data-sidebar-close></div>
        <main class="content" id="main-content" tabindex="-1">
<?php
        self::$menu=$nav;
        self::$quickCreates=$isSuperAdmin&&!$isMasterSupport?[]:array_values(array_filter($quickCreates,fn($c)=>$auth->can($c[4])));
    }

    private static array $menu=[];
    private static array $quickCreates=[];

    public static function flash(): void
    {
        foreach (['success','error','warning','info'] as $type) {
            $msg = flash($type);
            if ($msg) echo '<div class="alert alert-'.e($type).'">'.e($msg).'</div>';
        }
    }

    public static function footer(): void
    {
        ?>
        </main>
        <footer class="app-footer"><span>Igreja Master · <?=e(trim((string)file_get_contents(__DIR__.'/../VERSION')))?></span><span>Organização para cuidar melhor das pessoas.</span></footer>
    </div>
</div>
<dialog class="app-dialog quick-dialog" id="quick-create" aria-labelledby="quick-create-title"><div class="dialog-shell"><header class="dialog-heading"><div><span class="eyebrow">Por onde começar</span><h2 id="quick-create-title">O que você quer cadastrar?</h2><p>Escolha uma opção para abrir o formulário.</p></div><button class="icon-button" type="button" data-close-dialog aria-label="Fechar"><?=Ui::icon('close')?></button></header><div class="dialog-body"><div class="quick-create-grid"><?php foreach(self::$quickCreates as [$href,$name,$description,$icon]):?><a class="quick-create-option" href="/<?=e($href)?>?new=1"><span class="quick-icon"><?=Ui::icon($icon)?></span><span><strong><?=e($name)?></strong><small><?=e($description)?></small></span><?=Ui::icon('arrow')?></a><?php endforeach?></div></div></div></dialog>
<dialog class="app-dialog search-dialog" id="menu-search" aria-labelledby="menu-search-title"><div class="dialog-shell"><header class="dialog-heading"><div><h2 id="menu-search-title">Encontrar uma função</h2><p>Busque pelo nome da área que deseja abrir.</p></div><button class="icon-button" type="button" data-close-dialog aria-label="Fechar"><?=Ui::icon('close')?></button></header><div class="dialog-body"><label class="search-field" for="global-menu-query"><?=Ui::icon('search')?><input type="search" id="global-menu-query" placeholder="Ex.: membros, tesouraria, eventos" autocomplete="off"></label><div class="menu-results"><?php foreach(self::$menu as $href=>[$key,$label]):?><a href="/<?=e($href)?>" data-menu-result><?=e($label)?><?=Ui::icon('arrow')?></a><?php endforeach?></div><p id="menu-no-results" hidden>Nenhuma área encontrada. Tente outra palavra.</p></div></div></dialog>
<script id="app-ui-state" type="application/json"><?=json_encode(Ui::state(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?></script>
</body></html><?php
    }
}
