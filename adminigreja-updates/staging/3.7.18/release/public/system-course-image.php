<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/SystemCourseImageService.php';

$user=$auth->requireLogin();
if(!$auth->isSuperAdmin((string)$user['id'])&&!$auth->can('system_course')){
    http_response_code(403);exit;
}
$service=new SystemCourseImageService();
$id=(string)($_GET['img']??'');
if(!$service->approved($id)){
    http_response_code(404);header('Cache-Control: private, no-store');exit;
}
$meta=$service->image($id);
$file=$service->storagePath($id);
$etag='"'.(string)$meta['sha256'].'"';
header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Content-Disposition: inline; filename="tutorial.png"');
header('ETag: '.$etag);
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){
    http_response_code(304);exit;
}
header('Content-Length: '.filesize($file));
readfile($file);
exit;
