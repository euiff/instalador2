<?php
declare(strict_types=1);
require __DIR__.'/../public/lib/FinanceVisionConfig.php';

function expectKey(string $raw,string $wanted): void {
    $actual=FinanceVisionConfig::normalizeKey($raw);
    if($actual!==$wanted)throw new RuntimeException('Key normalization did not match input sample.');
}
function rejectKey(string $bad): void {
    try{FinanceVisionConfig::normalizeKey($bad);}catch(InvalidArgumentException $e){return;}
    throw new RuntimeException('An invalid key input was accepted.');
}
expectKey('AIzaSyABCDEFGHIJKLMNOPQRSTUVWXyZ01234567','AIzaSyABCDEFGHIJKLMNOPQRSTUVWXyZ01234567');
expectKey('auth.api:key+name/value=abcde.1234','auth.api:key+name/value=abcde.1234');
expectKey(" GEMINI_API_KEY=\"auth.abcdef12345+6789/xyz=\" \n",'auth.abcdef12345+6789/xyz=');
expectKey(str_repeat('a',900),str_repeat('a',900));
rejectKey('');
rejectKey('tiny');
rejectKey('https://aistudio.google.com/apikey');
rejectKey("auth-value1234\nX-Injected-Header: yes");
rejectKey('auth token with spaces');
rejectKey('{"apiKey":"not-a-key"}');
rejectKey(str_repeat('x',4097));
echo "OK Gemini legacy/auth key validation, long keys, and unsafe input rejection\n";
