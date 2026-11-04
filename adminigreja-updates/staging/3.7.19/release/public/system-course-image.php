<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/SystemCourseImageService.php';
require_once __DIR__.'/lib/SystemCourseIllustration.php';

$user=$auth->requireLogin();
if(!$auth->isSuperAdmin((string)$user['id'])&&!$auth->can('system_course')){
    http_response_code(403);exit('Não autorizado');
}
$service=new SystemCourseImageService();
$id=(string)($_GET['img']??'');
$meta=$service->image($id);
if(!$meta){
    http_response_code(404);header('Cache-Control: private, no-store');exit;
}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=1200');
header("Content-Security-Policy: default-src 'none'; style-src 'none'; sandbox");
if(!$service->approved($id)){
    // Offline fallback: every church sees visual guidance on first install.
    // This SVG contains ONLY fixed interface labels, never tenant/user records.
    $svg=SystemCourseIllustration::svg($meta);
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Content-Disposition: inline; filename="guia-visual-do-sistema.svg"');
    header('Content-Length: '.strlen($svg));
    echo $svg;
    exit;
}
$path=$service->storagePath($id);
$etag='"'.(string)$meta['sha256'].'"';
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="tutorial.png"');
header('ETag: '.$etag);
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){
    http_response_code(304);exit;
}
header('Content-Length: '.filesize($path));
readfile($path);
exit;
