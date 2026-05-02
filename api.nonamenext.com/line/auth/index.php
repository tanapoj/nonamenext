<?php

// for debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require './../credential.php';

$state = bin2hex(random_bytes(16));
$_SESSION['line_login_state'] = $state;
$url = "https://access.line.me/oauth2/v2.1/authorize?response_type=code&client_id=" . LINE_LOGIN_CHANNEL_ID . "&redirect_uri=" . urlencode(LINE_LOGIN_CALLBACK_URL) . "&state={$state}&scope=profile%20openid";
header("Location: $url");
exit();