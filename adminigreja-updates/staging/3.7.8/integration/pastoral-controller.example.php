<?php
declare(strict_types=1);
/**
 * INTEGRATION TEMPLATE ONLY — do not expose directly.
 * Copy logic into existing authenticated route and wire $context from server-side auth.
 * Never accept church_id or actor_id from client input.
 */
require_once __DIR__.'/../lib/PastoralCareService.php';
require_once __DIR__.'/../lib/PastoralCareQuery.php';

/**
 * @param array{church_id:int,user_id:int,can_view:bool,can_manage:bool} $context
 * The caller must compute permissions using the EXISTING role/policy service.
 */
function pastoralController(PDO $pdo, array $context): void
{
    $churchId=(int)($context['church_id']??0);
    $actorId=(int)($context['user_id']??0);
    if ($churchId<1 || $actorId<1 || empty($context['can_view'])) {
        http_response_code(403); echo 'Acesso negado'; return;
    }
    if (session_status()!==PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Authenticated session required');
    }
    if (empty($_SESSION['pastoral_csrf'])) {
        $_SESSION['pastoral_csrf']=bin2hex(random_bytes(32));
    }
    $csrfToken=(string)$_SESSION['pastoral_csrf'];
    $service=new PastoralCareService($pdo);
    $query=new PastoralCareQuery($pdo);
    $canManage=!empty($context['can_manage']);

    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!$canManage) {http_response_code(403);echo 'Acesso negado';return;}
        $token=(string)($_POST['_csrf']??'');
        if ($token==='' || !hash_equals($csrfToken,$token)) {
            http_response_code(419); echo 'Sessão expirada. Recarregue a página.';return;
        }
        if (($_POST['action']??'')!=='create') {
            http_response_code(400); echo 'Operação inválida';return;
        }
        $subject=trim((string)($_POST['subject']??''));
        if ($subject==='' || mb_strlen($subject)>180) {
            http_response_code(422);echo 'Informe um assunto válido';return;
        }
        $service->createCase($churchId,$actorId,$subject);
        header('Location: pastoral.php',true,303);
        return;
    }

    $page=max(1,min(100000,(int)($_GET['page']??1)));
    $status=(string)($_GET['status']??'');
    $term=mb_substr(trim((string)($_GET['q']??'')),0,180);
    try {
        $summary=$query->summary($churchId);
        $listing=$query->listing($churchId,null,$status,$term,$page);
    } catch (InvalidArgumentException) {
        http_response_code(400);echo 'Filtro inválido';return;
    }
    $baseUrl='pastoral.php';
    require __DIR__.'/../views/pastoral-dashboard.php';
}
