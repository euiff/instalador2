<?php

declare(strict_types=1);

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
        $isSuperAdmin = $auth->hasRole('super_admin', (string)$user['id']);
        $items = [
            'dashboard.php' => ['dashboard','Dashboard','⌂','dashboard'],
            'finance.php' => ['finance','Tesouraria','◈','finance'],
            'reports.php' => ['reports','Relatórios','▥','reports'],
            'needs.php' => ['needs','Necessidades','!','needs'],
            'members.php' => ['members','Membros','♙','secretary'],
            'documents.php' => ['documents','Documentos','▤','secretary'],
            'congregations.php' => ['congregations','Congregações','▣','secretary'],
            'events.php' => ['events','Eventos','□','secretary'],
            'event-registrations.php' => ['event-registrations','Inscrições de Eventos','☑','secretary'],
            'service-types.php' => ['service-types','Tipos de Culto','♢','secretary'],
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
        }
        ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?=e($title)?> · <?=e($church['name'] ?? 'Sistema de Gestão')?></title>
<link rel="stylesheet" href="/assets/app.css?v=7">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-logo"><?php if (!empty($church['logo_url'])): ?><img src="<?=e($church['logo_url'])?>" alt=""><?php else: ?>✦<?php endif; ?></div>
            <div><strong><?=e($church['name'] ?? 'Igreja Master')?></strong><small>Sistema de Gestão</small></div>
            <button class="sidebar-close" type="button" data-sidebar-close>×</button>
        </div>
        <?php if ($churches): ?>
        <form class="church-switch" method="post" action="switch-church.php">
            <?=csrf_field()?>
            <label for="church_id">Igreja ativa</label>
            <select id="church_id" name="church_id" onchange="this.form.submit()">
                <?php foreach ($churches as $c): ?><option value="<?=e($c['id'])?>" <?=$church && $church['id']===$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <nav class="nav-list">
            <?php foreach ($nav as $href => [$key,$label,$icon]):
                $isActive = $active === $href || $active === $key;
            ?>
            <a class="nav-item <?=$isActive?'active':''?>" href="<?=$href?>"><span class="nav-icon"><?=$icon?></span><span><?=e($label)?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-user">
            <div class="avatar"><?=e(mb_strtoupper(mb_substr($user['email'],0,1)))?></div>
            <div class="sidebar-user-text"><strong><?=e($user['full_name'] ?: $user['email'])?></strong><small><?=e($user['email'])?></small></div>
            <a class="logout" href="logout.php" title="Sair">↪</a>
        </div>
    </aside>
    <div class="page-wrap">
        <header class="mobile-header"><button type="button" class="menu-button" data-sidebar-open>☰</button><div class="mobile-brand"><strong><?=e($church['name'] ?? 'Igreja Master')?></strong><small>Sistema de Gestão</small></div></header>
        <div class="sidebar-overlay" data-sidebar-close></div>
        <main class="content">
<?php
    }

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
    </div>
</div>
<script>
const sidebar=document.getElementById('sidebar');
const overlay=document.querySelector('.sidebar-overlay');
function openSidebar(){sidebar?.classList.add('open');overlay?.classList.add('show');document.body.classList.add('no-scroll')}
function closeSidebar(){sidebar?.classList.remove('open');overlay?.classList.remove('show');document.body.classList.remove('no-scroll')}
document.querySelectorAll('[data-sidebar-open]').forEach(x=>x.addEventListener('click',openSidebar));
document.querySelectorAll('[data-sidebar-close]').forEach(x=>x.addEventListener('click',closeSidebar));
</script>
</body></html><?php
    }
}
