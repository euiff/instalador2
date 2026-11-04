<?php
declare(strict_types=1);
require __DIR__.'/../../3.7.18/tests/integration.php';
require_once $dir.'/public/lib/SystemCourseIllustration.php';

$imageService=new SystemCourseImageService();
$first=$imageService->forLesson('primeiros-passos-01');
$check(count($first)===1,'first lesson has one picture from the opening dashboard');
$check(($first[0]['id']??'')==='01-painel-inicial','first lesson image is mapped to current home screen');
$check(($first[0]['is_illustration']??false)===true,'visual guide appears before images ZIP is imported');
$check(count($imageService->forLesson('primeiros-passos-02'))===1,'same picture is also available on navigation lesson');
$all=$imageService->catalog();
$check(count($all)===17,'17 approved tutorial picture mappings still exist');

foreach($all as $im){
    $pictures=$imageService->forLesson($im['lesson_slug']);
    $matched=false;
    foreach($pictures as $pic)if(($pic['id']??'')===$im['id'])$matched=true;
    $check($matched,'mapped lesson has a visible illustrated guide before ZIP import');
    $svg=SystemCourseIllustration::svg($im);
    $check(str_starts_with($svg,'<?xml')&&str_contains($svg,'<svg')&&str_contains($svg,'</svg>'),
       'automatic guide is safe standalone SVG markup');
    $check(str_contains($svg,'EXEMPLO SEM DADOS REAIS'),'illustration is explicitly fictional');
    $check(!str_contains($svg,'@gmail.com')&&!str_contains($svg,'@hotmail.com'),
       'svg guide does not embed an email account');
    $dom=new DOMDocument();
    $check($dom->loadXML($svg,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)===true,
       'generated visual guide is parseable XML');
}

// Public login uses historical standalone page instead of authenticated View::header.
// Ensure this scoped fix does not add scripts or otherwise modify non-login markup.
$before=$_SERVER['REQUEST_URI']??null;
$_SERVER['REQUEST_URI']='/dashboard.php';
ob_start();
$outside=new Auth($pdo);
echo '<!doctype html><html><head></head><body><input type="password"></body></html>';
$untouched=ob_get_clean();
$check(!str_contains($untouched,'password-visibility.js'),
       'other pages are not modified by the public login asset hook');

// Simulate a public /login render, without knowing any real passwords or users.
$_SERVER['REQUEST_URI']='/login';
ob_start();
$publicLoginAuth=new Auth($pdo);
echo '<!doctype html><html><head><title>Entrar no Igreja Master</title></head><body>'
    .'<form><input name="email" type="email"><input name="password" type="password"></form>'
    .'</body></html>';
ob_end_flush();
$html=ob_get_clean();
$check(str_contains($html,'password-visibility.js?v=3719'),
  'public login page includes password eye JS');
$check(str_contains($html,'password-visibility.css?v=3719'),
  'public login page includes eye CSS');
$check(strpos($html,'password-visibility.js')<strpos($html,'</head>'),
  'password eye assets loaded inside login HTML HEAD');
$check(substr_count($html,'password-visibility.js')===1,
  'login asset injection is not duplicated');
$check(str_contains($html,'name="password" type="password"'),
  'login form remains unchanged, password never sent to injection logic');
$check(!str_contains($html,' MinhaSenha '),'no password values added by render hook');
if($before!==null)$_SERVER['REQUEST_URI']=$before;else unset($_SERVER['REQUEST_URI']);

echo "PASS 3.7.19 standalone login password eye scoped safely, first lesson visible, all 17 automatic SVG visuals parseable, approved-image fallback and privacy\n";
