<?php
declare(strict_types=1);

final class Ui
{
    public static function icon(string $name): string
    {
        $paths=[
            'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
            'people'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M15 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
            'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-12 5h2m4 0h2"/>',
            'wallet'=>'<path d="M20 8V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v12H5a3 3 0 0 1-3-3V6m14 7h6v5h-6z"/>',
            'chart'=>'<path d="M3 3v18h18M8 16v-5m5 5V7m5 9v-9"/>',
            'file'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm0 0v6h6M8 13h8m-8 4h5"/>',
            'heart'=>'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>',
            'building'=>'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-4h6v4M8 7h2m4 0h2M8 12h2m4 0h2"/>',
            'message'=>'<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/>',
            'settings'=>'<path d="m9 3-.6 2.3-2 .9L4 5.5 2 9l1.7 1.7v2.6L2 15l2 3.5 2.4-.7 2 .9L9 21h4l.6-2.3 2-.9 2.4.7 2-3.5-1.7-1.7v-2.6L20 9l-2-3.5-2.4.7-2-.9L13 3z"/><circle cx="11" cy="12" r="3"/>',
            'search'=>'<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
            'plus'=>'<path d="M12 5v14M5 12h14"/>',
            'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
            'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
            'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
            'check'=>'<path d="m5 12 4 4L19 6"/>',
        ];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['file']).'</svg>';
    }

    public static function newButton(string $id,string $label='Novo cadastro',bool $primary=true): string
    {
        return '<button class="btn '.($primary?'btn-primary':'btn-light').'" type="button" data-open-dialog="'.e($id).'">'.self::icon('plus').e($label).'</button>';
    }

    public static function state(): array
    {
        $page=basename((string)($_SERVER['SCRIPT_NAME']??''));
        if(!in_array($page,['members.php','congregations.php','events.php','service-types.php','users.php','needs.php','visitors.php','pastoral-care.php','departments.php','assets.php','groups.php','campaigns.php','polls.php','raffles.php','prayer-clock.php','event-registrations.php','prayers.php','finance.php'],true)) return ['isPost'=>false,'post'=>[]];
        $draft=$_SESSION['_ui_drafts'][$page]??null;
        unset($_SESSION['_ui_drafts'][$page]);
        if($_SERVER['REQUEST_METHOD']!=='POST' && is_array($draft) && ($draft['church']??null)===($_SESSION['church_id']??null) && time()-(int)($draft['time']??0)<300) return ['isPost'=>true,'post'=>$draft['post']];
        $post=[];
        if($_SERVER['REQUEST_METHOD']==='POST') {
            foreach($_POST as $key=>$value) {
                if(preg_match('/pass|senha|secret|token|csrf|api.?key/i',(string)$key)) continue;
                if(is_scalar($value) || is_array($value)) $post[$key]=$value;
            }
        }
        return ['isPost'=>$_SERVER['REQUEST_METHOD']==='POST','post'=>$post];
    }

    public static function rememberPost(string $page): void
    {
        if($_SERVER['REQUEST_METHOD']!=='POST')return;
        $state=self::state();
        $_SESSION['_ui_drafts'][$page]=['church'=>$_SESSION['church_id']??null,'time'=>time(),'post'=>$state['post']];
    }
}
