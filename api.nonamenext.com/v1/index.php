<?php

// for debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(['cookie_domain' => '.nonamenext.com']);

// จัดการ CORS ให้รองรับ www และ non-www และ Credentials (Session)
$allowed_origins = [
    'https://nonamenext.com',
    'https://www.nonamenext.com',
    'https://api.nonamenext.com',
    'http://localhost:5500', // สำหรับเทสในเครื่อง
    'http://127.0.0.1:5500'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ==========================================
// 0. Helper Classes & Functions
// ==========================================
class MyJsonResponse {
    private $data;
    private $status;
    public function __construct($data, $status = 200) {
        $this->data = json_encode($data);
        $this->status = $status;
    }
    public function getStatus() { return $this->status; }
    public function __toString() { return $this->data; }
}

function res_json($data, $status = 200) { return new MyJsonResponse($data, $status); }

function getUser() {
    if (isset($_SESSION['user_id'])) {
        $user = get_user($_SESSION['user_id']);
        if ($user) {
            return $user;
            // return ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username']];
        }
    }
    return null;
}

// ==========================================
// 1. Simple Router
// ==========================================
class SimpleApp {
    private $routes = [];
    const BASE_PATH = '/v1'; 

    public function get($route, $callback) { $this->routes['GET'][$route] = $callback; }
    public function post($route, $callback) { $this->routes['POST'][$route] = $callback; }
    
    public function run() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        if (strpos($path, self::BASE_PATH) === 0) {
            $path = substr($path, strlen(self::BASE_PATH));
        }
        if($path === '' || $path === false) $path = '/';

        $req_data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (isset($this->routes[$method][$path])) {
            $callback = $this->routes[$method][$path];
            $response = $callback($req_data);
            if ($response instanceof MyJsonResponse) {
                http_response_code($response->getStatus());
                echo $response;
            } else {
                echo is_array($response) ? json_encode($response) : $response;
            }
        } else {
            $response = res_json(['error' => 'Route not found: ' . $path], 404);
            http_response_code($response->getStatus());
            echo $response;
        }
    }
}

// ==========================================
// 2. Database Connection (Include db.php ในของจริง)
// ==========================================
require 'db.php'; 

// ==========================================
// 3. Application Routes
// ==========================================
$app = new SimpleApp();

require 'service.php'; 
// ==========================================
// HTTP ROUTES (Web Endpoints)
// เรียกใช้งานจาก SimpleApp
// ==========================================

// --- AUTH ---
$app->get('/auth/me', function() {
    $user = getUser();
    return $user ? res_json(['logged_in' => true] + $user) : res_json(['logged_in' => false, '_SESSION' => $_SESSION], 401);
});

$app->post('/auth/login', function($req) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$req['username']]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($req['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        return res_json(['success' => true, 'username' => $user['username']]);
    }
    return res_json(['error' => 'Invalid credentials'], 401);
});

$app->post('/auth/logout', function() {
    session_destroy();
    return res_json(['success' => true]);
});

// --- MATCHES ---
$app->get('/matches/today', function() {
    return res_json(['matches' => get_today_matches_data()]);
});

$app->post('/matches/save', function($req) {
    $user = getUser();
    if (!$user) return res_json(['error' => 'Unauthorized'], 401);
    
    save_today_matches_data($req['matches'], $user['id']);
    return res_json(['success' => true]);
});

$app->post('/matches/undo', function() {
    $user = getUser();
    if (!$user) return res_json(['error' => 'Unauthorized'], 401);
    
    if (undo_today_matches_data()) {
        return res_json(['success' => true]);
    }
    return res_json(['error' => 'No actions'], 400);
});

// --- PLAYERS ---
$app->get('/players', function() {
    $user = getUser();
    if (!$user) return res_json(['error' => 'Unauthorized'], 401);
    
    $player_data = get_all_players_data();

    $coll = new Collator('th_TH');
    usort($player_data, function($a, $b) use($coll) {
        return $coll->compare($a['displayName'], $b['displayName']);
    });

    return res_json(['players' => $player_data]);
});

$app->get('/players/today', function() {
	$player_data = get_today_players_data();

    $coll = new Collator('th_TH');
    usort($player_data, function($a, $b) use($coll) {
        return $coll->compare($a['name'], $b['name']);
    });

    return res_json(['players' => $player_data]);
});

$app->post('/players/save', function($req) {
    $user = getUser();
    if (!$user) return res_json(['error' => 'Unauthorized'], 401);
    
    // สร้างข้อความสรุปก่อนทำการอัปเดตเพื่อนำมาเปรียบเทียบ
    $old_summary_message = generate_line_summary_message();
    
    // 1. Admin บันทึกข้อมูลลง Database
    save_today_players_data($req['players'], $user['id']);
    
    // สร้างข้อความสรุปล่าสุดหลังจากบันทึกข้อมูลเสร็จแล้ว
    $new_summary_message = generate_line_summary_message();
    
    // 2. เช็กว่าข้อมูลมีการเปลี่ยนแปลงทำให้ข้อความเปลี่ยนไปหรือไม่ แล้วค่อยยิง Push Message
    if ($old_summary_message !== $new_summary_message) {
		
		// ห้องเทสส่วนตัว
        //$target_group_id = 'C40a945607478d1be8cb9ef11b48cfb17'; 
		
		// ห้องเทสที่มีพี่เบนซ์+พี่บิ๊กอยู่ด้วย
        $target_group_id = 'Cc37a5be1ea25fe3b5afa5dba644684f4'; 
		
		// ห้องหลัก NoNameNext
        //$target_group_id = 'Cd03e2dd52bc6fdae1181b565ec50a368'; 
		
        send_line_push($target_group_id, $new_summary_message);
    }
    
    return res_json(['success' => true]);
});

// --- BILLING ---
$app->get('/billing/today', function() {
	$calculate_billing_data = calculate_today_billing_data();
	usort($calculate_billing_data['billing'], function($a, $b){
		return $a['name'] <=> $b['name'];
	});
    return res_json($calculate_billing_data);
});

$app->post('/billing/pay', function($req) {
    $user = getUser();
    if (!$user) return res_json(['error' => 'Unauthorized'], 401);
    
    if (mark_player_as_paid($req['playerName'], $user['id'])) {
        return res_json(['success' => true]);
    }
    return res_json(['error' => 'Player not found'], 404);
});

$app->run();