<?php

// ==========================================
// CORE FUNCTIONS (Reusable Logic)
// สามารถเรียกใช้จาก LINE API, Webhook หรือ Cron ได้โดยตรง
// ==========================================

function get_today_matches_data() {
    $db = getDB();
    $stmt = $db->prepare("SELECT match_data FROM match_snapshots WHERE session_date = CURDATE() ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $res = $stmt->fetch();
    return $res ? json_decode($res['match_data'], true) : [];
}

function save_today_matches_data($matches, $userId) {
    // 1. ดึงข้อมูล Snapshot ล่าสุดออกมาก่อน
    $latest_data = get_today_matches_data();
    
    // 2. เปรียบเทียบ JSON แบบ String เพื่อความแม่นยำ 
    // ถ้าข้อมูลเหมือนกันเป๊ะ ให้ข้ามการบันทึก (Return true ถือว่าการเซฟสมบูรณ์)
    if (json_encode($latest_data) === json_encode($matches)) {
        return true; 
    }

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO match_snapshots (session_date, match_data, created_by) VALUES (CURDATE(), ?, ?)");
    return $stmt->execute([json_encode($matches), $userId]);
}

function undo_today_matches_data() {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM match_snapshots WHERE session_date = CURDATE() ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $latest = $stmt->fetch();
    if ($latest) {
        $del = $db->prepare("DELETE FROM match_snapshots WHERE id = ?");
        return $del->execute([$latest['id']]);
    }
    return false;
}

function get_all_players_data() {
    $db = getDB();
    $stmt = $db->query("SELECT id, username, role, displayName FROM users WHERE displayName IS NOT NULL AND displayName != '' AND displayName != '---' ORDER BY displayName ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
	return $users;
}

function get_user($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, role, lineUserId, displayName, created_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
	return $user ? $user : null;
}

function get_today_players_data() {
    $db = getDB();
    $stmt = $db->prepare("SELECT player_data FROM player_snapshots ORDER BY id DESC LIMIT 1");
    //$stmt = $db->prepare("SELECT player_data FROM player_snapshots WHERE session_date = CURDATE() ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $res = $stmt->fetch();
    $player_data = $res ? json_decode($res['player_data'], true) : [];
	return $player_data;
}

function save_today_players_data($players, $userId) {
    $db = getDB();
	if (!$userId) {
		$userId = 1;
	}
    $stmt = $db->prepare("INSERT INTO player_snapshots (session_date, player_data, created_by) VALUES (CURDATE(), ?, ?)");
    return $stmt->execute([json_encode($players), $userId]);
}

function calculate_today_billing_data() {
    $players = get_today_players_data();
    $matches = get_today_matches_data();

    $billing = [];
    foreach ($players as $p) {
        $billing[$p['name']] = [
            'name' => $p['name'], 
            'status' => $p['status'], 
            'shuttles' => []
        ];
    }

    foreach ($matches as $m) {
        if ($m['status'] === 'played' && !empty($m['shuttles'])) {
            $shuttleList = preg_split("/[\s,]+/", $m['shuttles'], -1, PREG_SPLIT_NO_EMPTY);
            
            foreach (['p1', 'p2', 'p3', 'p4'] as $px) {
                $pName = $m[$px] ?? '';
                if ($pName && isset($billing[$pName])) {
                    $billing[$pName]['shuttles'] = array_merge($billing[$pName]['shuttles'], $shuttleList);
                }
            }
        }
    }

    $max_cols = 8;
    $final_billing = [];

    foreach ($billing as $name => $data) {
        $unique_shuttles = array_unique($data['shuttles']);
        sort($unique_shuttles);
        
        $count = count($unique_shuttles);
        $total = 100 + ($count * 25); 

        if ($count > $max_cols) $max_cols = $count;

        $final_billing[] = [
            'name' => $name,
            'status' => $data['status'],
            'shuttles' => $unique_shuttles,
            'total' => $total
        ];
    }

    return [
        'billing' => $final_billing,
        'max_cols' => $max_cols
    ];
}

function mark_player_as_paid($playerName, $userId) {
    $players = get_today_players_data();
    $found = false;

    foreach ($players as &$p) {
        if ($p['name'] === $playerName) {
            $p['status'] = 'left'; 
            $found = true;
            break;
        }
    }
    
    if ($found) {
        return save_today_players_data($players, $userId);
    }
    return false;
}

// ==========================================
// LINE BOT INTEGRATION LOGIC
// ==========================================

require_once __DIR__ . '/../line/credential.php';

// ดึง Display Name ของ User ในกลุ่ม LINE
function get_line_group_member_profile($groupId, $userId) {
    $url = "https://api.line.me/v2/bot/group/{$groupId}/member/{$userId}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true);
    return $data['displayName'] ?? null;
}

// เพิ่มผู้เล่น 1 คน (จากการพิมพ์ "noname ลงชื่อ")
function add_single_player_from_line($playerName) {
    $players = get_today_players_data();
    $found_index = -1;
    
    // ค้นหาว่ามีชื่อนี้ในระบบแล้วหรือยัง
    foreach ($players as $index => $p) {
        if (strcasecmp(trim($p['name']), trim($playerName)) == 0) {
            $found_index = $index;
            break;
        }
    }
    
    if ($found_index !== -1) {
        // ถ้ามีชื่ออยู่แล้ว แต่สถานะคือ "เลิกเล่น/จ่ายเงินแล้ว" ให้เปลี่ยนกลับมาพร้อมเล่น
        if ($players[$found_index]['status'] === 'left') {
            $players[$found_index]['status'] = 'ready';
            save_today_players_data($players, 0);
        }
        // ถ้าสถานะ ready อยู่แล้ว ก็ไม่ต้องทำอะไรซ้ำ
        return true; 
    } else {
        // ถ้ายังไม่มีชื่อ ให้เพิ่มใหม่ต่อท้าย
        $players[] = [
            'name' => trim($playerName),
            'status' => 'ready',
            'note' => '' // ลงชื่อไวแบบนี้จะไม่มี Note
        ];
        save_today_players_data($players, 0);
        return true;
    }
}

function sync_players_from_line($line_players) {
    $current_players = get_today_players_data();
    
    $db_names = array_column($current_players, 'name');
    $line_names = array_column($line_players, 'name');
    
    $new_names = array_diff($line_names, $db_names);
    $removed_names = array_diff($db_names, $line_names);

    $updated_players = [];
    
    foreach ($current_players as $p) {
        if (in_array($p['name'], $removed_names)) continue; 
        
        foreach ($line_players as $lp) {
            if ($lp['name'] === $p['name']) {
                $p['note'] = $lp['note'];
                break;
            }
        }
        $updated_players[] = $p;
    }

    foreach ($new_names as $name) {
        $note = '';
        foreach ($line_players as $lp) {
            if ($lp['name'] === $name) { $note = $lp['note']; break; }
        }
        $updated_players[] = ['name' => $name, 'status' => 'ready', 'note' => $note];
    }

    save_today_players_data($updated_players, 0);
    return $updated_players;
}

function generate_line_summary_message() {
    date_default_timezone_set('Asia/Bangkok'); 
    $players = get_today_players_data();
    
    $active_players = [];
    foreach ($players as $p) {
        if ($p['status'] !== 'left') {
            $active_players[] = $p;
        }
    }
    
    $timestamp = time();
    if (date('N', $timestamp) == 1) { 
        $monday_timestamp = $timestamp;
    } else {
        $monday_timestamp = strtotime('next monday', $timestamp);
    }
    
    $thai_months = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
    ];
    
    $day = date('j', $monday_timestamp);
    $month = $thai_months[(int)date('n', $monday_timestamp)];
    $year = date('Y', $monday_timestamp);
    
    $date_str = "วันจันทร์ ที่ {$day} {$month} {$year}";

    $player_lines = [];
    $total_slots = max(28, count($active_players));
    
    for ($i = 1; $i <= $total_slots; $i++) {
        if ($i === 27) {
            $player_lines[] = "สำรอง 2";
        }
        
        $player_text = "";
        if (isset($active_players[$i - 1])) {
            $p = $active_players[$i - 1];
            $note = !empty($p['note']) ? " " . $p['note'] : "";
            $player_text = $p['name'] . $note;
        }
        
        $player_lines[] = "{$i}. {$player_text}";
    }
    
    $player_list_str = implode("\n", $player_lines);

    $msg = "@All  📣 ชวนพี่ ๆ เพื่อน ๆ น้อง ๆ มาตีแบดกันครับ!\n"
         . "ก๊วน NoNameNext เปิดรับสมัครสมาชิกลงคอร์ดแล้ว 🎉\n"
         . "🗓 {$date_str}\n"
         . "⏰ เวลา 19:00 – 23:00 น.(เวลาเลิกอาจจะมีการเปลี่ยนแปลง ขึ้นอยู่กับสมาชิก ที่อยู่หลัง 22:00)\n"
         . "📍 คอร์ด Tier1 สาธุประดิษฐ์ 58\n"
         . "🏸 คอร์ดที่ 7, 8, 9 (ช่วง 2-3ทุ่ม) หลัง 3 ทุ่ม เหลือ 2 คอร์ด (8, 9) \n"
         . "👥 รับจำนวน 26 ท่านสำรอง 2 ท่านเท่านั้น!\n"
         . "ใครอยากมาออกเหงื่อ ตบให้สุด หยอดให้ยับ\n"
         . "มาสนุกด้วยกันแบบกันเอง สไตล์ก๊วน NoNameNext 💪🔥\n"
         . "👉 ลงชื่อพร้อมระบุเวลาที่จะมาด้วยนะครับ\n"
         . "(เช่น มา 19:00 / มาสายหน่อย 20:00 หรือ 21:00 เป็นต้น)\n"
         . "ครบแล้วปิดรับทันทีนะครับ รีบเลย! 😎🏸\n\n"
         . "🗺️🚒สถานที่ตามลิงค์นี้เลยครับ\n"
         . "TIER1 badminton court\n"
         . " Map : https://maps.app.goo.gl/ED6TSrdBhdrrLraD8?g_st=al\n\n"
         . "ลงชื่อ\n"
         . $player_list_str;

    return $msg;
}

// ยิง LINE API (Reply: ตอบกลับในแชททันทีหลังจากคนพิมพ์)
function send_line_reply($replyToken, $messageText) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $data = [
        'replyToken' => $replyToken,
        'messages' => [['type' => 'text', 'text' => $messageText]]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// ยิง LINE API (Push: บอทเป็นฝ่ายส่งไปเอง เช่น ตอนแอดมินแก้เว็บ)
function send_line_push($to, $messageText) {
    $url = 'https://api.line.me/v2/bot/message/push';
    $data = [
        'to' => $to,
        'messages' => [['type' => 'text', 'text' => $messageText]]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// ค้นหาชื่อที่ถูก Mapping ไว้จากฐานข้อมูล (ตาราง users)
function get_mapped_display_name_by_line_id($lineUserId) {
    if (!$lineUserId) return null;
    
    $db = getDB();
    $stmt = $db->prepare("SELECT displayName FROM users WHERE lineUserId = ? AND lineUserId IS NOT NULL");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    // ถ้าเจอข้อมูลในระบบ และ displayName ไม่ใช่ค่าว่างหรือค่า Default '---'
    if ($user && !empty($user['displayName']) && $user['displayName'] !== '---') {
        return trim($user['displayName']);
    }
    
    return null;
}