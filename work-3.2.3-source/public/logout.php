<?php
require __DIR__ . '/bootstrap.php';
$auth->logout();
redirect('/login.php');
