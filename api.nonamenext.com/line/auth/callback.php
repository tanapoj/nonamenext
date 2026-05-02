<?php

// for debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(['cookie_domain' => '.nonamenext.com']);

require './../credential.php';
require './../../v1/db.php';

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

file_put_contents('log.txt', '-----------------------------' . PHP_EOL . PHP_EOL, FILE_APPEND);
file_put_contents('log.txt', json_encode($_GET, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL, FILE_APPEND);

// ตรวจสอบ State ป้องกัน CSRF
if (empty($code) || $state !== ($_SESSION['line_login_state'] ?? '')) {
    header("Location: https://www.nonamenext.com/u/?error=line_login_failed");
    exit();
}

// แลก Code เพื่อขอ Access Token
$token_url = "https://api.line.me/oauth2/v2.1/token";
$data = [
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => LINE_LOGIN_CALLBACK_URL,
    'client_id' => LINE_LOGIN_CHANNEL_ID,
    'client_secret' => LINE_LOGIN_CHANNEL_SECRET
];

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$result = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($result, true);
$access_token = $token_data['access_token'] ?? '';

file_put_contents('log.txt', '=== token_data ===' . PHP_EOL . PHP_EOL, FILE_APPEND);
file_put_contents('log.txt', json_encode($token_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL, FILE_APPEND);

if (empty($access_token)) {
    header("Location: https://www.nonamenext.com/u/?error=line_login_failed");
    exit();
}

// นำ Token ไปขอข้อมูล Profile เพื่อเอา userId ของ LINE
$profile_url = "https://api.line.me/v2/profile";
$ch2 = curl_init($profile_url);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
$profile_result = curl_exec($ch2);
curl_close($ch2);

$profile = json_decode($profile_result, true);
$lineUserId = $profile['userId'] ?? '';

file_put_contents('log.txt', '=== profile ===' . PHP_EOL . PHP_EOL, FILE_APPEND);
file_put_contents('log.txt', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL, FILE_APPEND);

if (empty($lineUserId)) {
    header("Location: https://www.nonamenext.com/u/?error=line_login_failed");
    exit();
}

// ตรวจสอบในตาราง users ว่ามี lineUserId นี้ผูกไว้หรือไม่
$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE lineUserId = ? AND lineUserId IS NOT NULL AND lineUserId != ''");
$stmt->execute([$lineUserId]);
$user = $stmt->fetch();

file_put_contents('log.txt', '=== user ===' . PHP_EOL . PHP_EOL, FILE_APPEND);
file_put_contents('log.txt', json_encode($user, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL, FILE_APPEND);

if ($user) {
    // ล็อกอินสำเร็จ 
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    header("Location: https://www.nonamenext.com"); // กลับหน้าหลัก
    exit();
} else {
    // ไม่พบข้อมูลในระบบ (ยังไม่ผูกไอดี)
    header("Location: https://www.nonamenext.com/u/?error=line_not_found");
    exit();
}