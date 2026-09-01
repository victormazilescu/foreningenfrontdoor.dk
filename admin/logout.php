<?php
session_start(['name'=>'fd_session']);
$_SESSION = []; session_destroy();
header('Location: /admin/index.php'); exit;
