<?php
require __DIR__.'/bootstrap.php';
$auth->requireAbility('department_portal');
redirect('/portal.php?view=department');
