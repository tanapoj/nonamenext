<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ==========================================
// 1. ฟังก์ชันสำหรับประมวลผลข้อความ
// ==========================================
function extract_players_from_text($text) {
    $players = [];
    $lines = explode("\n", $text);
    
    foreach ($lines as $line) {
        // จับเฉพาะบรรทัดที่ขึ้นต้นด้วยตัวเลขและช่องว่าง
        if (preg_match('/^\s*\d+\.?[\s\x{00A0}]+(.*)$/u', $line, $matches)) {
            $rest = trim($matches[1], " \t\n\r\0\x0B\xC2\xA0");
            
            if (!empty($rest)) {
                // แยกชื่อและเวลา(Note)
                // คำอธิบาย Regex:
                // ^(.*?) = Group 1 (Name): จับข้อความทั้งหมดตั้งแต่เริ่มต้นแบบกวาดน้อยที่สุด (Lazy)
                // (?:[\s\x{00A0}]+(\d{1,2}[:.]\d{1,2}.*))?$ = Group 2 (Note): 
                //    มองหาช่องว่างที่ตามด้วยตัวเลขเวลา (xx:xx หรือ xx.xx) และข้อความที่ต่อท้ายจนจบประโยค (มีหรือไม่มีก็ได้)
                if (preg_match('/^(.*?)(?:[\s\x{00A0}]+(\d{1,2}[:.]\d{1,2}.*))?$/u', $rest, $parts)) {
                    $name = trim($parts[1]);
                    $note = isset($parts[2]) ? trim($parts[2]) : '';
                } else {
                    $name = $rest;
                    $note = '';
                }
                
                // กันเหนียว กรณีบรรทัดนั้นเป็นแค่เครื่องหมายขีดทิ้งไว้ หรือถูกตัดจนว่าง
                if (preg_match('/^[.-]+$/', $name) || empty($name)) continue; 

                $players[] = [
                    'name' => $name,
                    'note' => $note
                ];
            }
        }
    }
    return $players;
}

// ==========================================
// 2. รับ Webhook และตรวจสอบเงื่อนไข
// ==========================================

$req_body = file_get_contents('php://input');

// DEBUG
// $req_body = '{"destination":"U83115cab5b28760649c30b5af542ecf8","events":[{"type":"message","message":{"type":"text","id":"611747493763612851","quoteToken":"HCA-E34Wz_4i7Mk0nYmaH8VfaqXbsNDc1LO7J4394Lt8gmhV6q_u9hRbrMCuxJSEBc8--_f8EYRuwqIW1IDGtu_EIO3x4k19zxPJn9mZewyqw_IcId3rx9eAeXmVrLCZwft-IOie77fHHKvr-1K_LQ","markAsReadToken":"5SQ92VnWr29GWM8WBPt15mhCkuiPwiX75J3woFHEQgT01AYSDsAZJVYAbVhCHGTMSmORUsQcTfnGa4e5y5ktg-jzgwj36-9qZL4YK1kKAFDpA-sJZ0RXNgtQsEUoPSdcMi52h6Z5jZA2cv7ROfShA-Asixi1LUKi6elKXoWxKN77vopo8cIysgl53k2EfIVdT0uAUk3-46Ch4ELGeImycw","text":" ก๊วน NoNameNext อัปเดตรายชื่อล่าสุด (จากแอดมิน):\nลงชื่อ\n1. พี่บิ๊ก 19:00\n2. ต้า\n3. เบนซ์ 19:00\n4. ก้อย\n5. ตุ้ย 19:00\n6. ทดสอบ1 19:00\n7. ทดสอบ2\n8. ทดสอบ4  19:00"},"webhookEventId":"01KQCF3SA5F19SS8HCQHTJPM28","deliveryContext":{"isRedelivery":false},"timestamp":1777461224590,"source":{"type":"group","groupId":"C40a945607478d1be8cb9ef11b48cfb17","userId":"U1ae81f2d8b813dbe00140ab20932d1d0"},"replyToken":"8c94ee6ddda14bb2a10a795aa32416c2","mode":"active"}]}';

//$req_body = '{"destination":"U83115cab5b28760649c30b5af542ecf8","events":[{"type":"message","message":{"type":"text","id":"611764056935891353","quoteToken":"bratYJTJDdoNBZdybKuz2oxpozeDsy51K_EDipOd8V5fGGomJvIg_Y8d0YBGVZiqqTQKwYeQlhOmhymVVE4ACzQWmpie9pNl4bNFiFThLxs1uyB70nMxzSjxuk7q2CnkKVCeBpMyaCiSGujjBRzuaw","markAsReadToken":"AN4nSUwXHmEQnhnnKpwzXygnX2th87NXrSPIG9INBzBImXoJ1YvOureprDoN73yNd87trz0MGDBRuUsJFfyppclkYqCbKsQlJmvLp4qAl-WeF_Q8IkWI6mF_RWxGlWzvavJ7JpBTJo04IZ4UTWWTlekpWy6JHc5Ca3G9Ss9_CGElMlhyIiq_J9-4Z4uAcpBRYbgzNc9MmV9NG5PbMLgQ5A","text":"noname ลงชื่อ"},"webhookEventId":"01KQCRH2NCRVVQK11XWBWYF61J","deliveryContext":{"isRedelivery":false},"timestamp":1777471097015,"source":{"type":"group","groupId":"C40a945607478d1be8cb9ef11b48cfb17","userId":"U1ae81f2d8b813dbe00140ab20932d1d0"},"replyToken":"20ae1c98f050468b8563baa4274f67ae","mode":"active"}]}';

$data = json_decode($req_body, true);

file_put_contents('log.txt', '-------------------------------------------------------' . PHP_EOL . PHP_EOL, FILE_APPEND);
file_put_contents('log.txt', file_get_contents('php://input') . PHP_EOL . PHP_EOL, FILE_APPEND);


$ALLOWED_GROUP_ID = [
	// ห้องเทสส่วนตัว
    'C40a945607478d1be8cb9ef11b48cfb17',
	// ห้องเทสที่มีพี่เบนซ์+พี่บิ๊กอยู่ด้วย
	'Cc37a5be1ea25fe3b5afa5dba644684f4', 
	// ห้องหลัก NoNameNext
	'Cd03e2dd52bc6fdae1181b565ec50a368', 
];

if (empty($data['events'][0])) {
    echo "No event found.";
    exit();
}

$event = $data['events'][0];

// 1. ตรวจสอบ Source
$sourceType = $event['source']['type'] ?? '';
$groupId = $event['source']['groupId'] ?? '';
$userId = $event['source']['userId'] ?? '';

if ($sourceType !== 'group' || !in_array($groupId, $ALLOWED_GROUP_ID)) {
    echo "Ignored: Not from the allowed group chat.";
    exit();
}

// 2. ตรวจสอบชนิดข้อความ
if (empty($event['message']['text'])) {
    echo "Ignored: Not a text message.";
    exit();
}

$text = trim($event['message']['text']);

// ==========================================
// 3. ตรวจสอบ Keyword (แยกเป็น 2 รูปแบบ)
// ==========================================

// รูปแบบที่ 1: พิมพ์ลงชื่อด่วน "noname ลงชื่อ"
$is_quick_register = trim(strtolower($text)) == 'noname ลงชื่อ';

// รูปแบบที่ 2: ก๊อปปี้เทมเพลตยาวๆ มาวาง
$is_normal_update = str_contains($text, 'NoNameNext') && str_contains($text, 'ลงชื่อ');

if (!$is_quick_register && !$is_normal_update) {
    echo "Ignored: No Keyword.";
    exit();
}

// ==========================================
// ผ่าน Guard ทั้งหมดแล้ว นำเข้าข้อมูลได้
// ==========================================

require_once __DIR__ . '/../v1/db.php';
require_once __DIR__ . '/../v1/service.php';
require_once __DIR__ . '/credential.php';

if ($is_quick_register) {
    // ---- FLOW 1: ลงชื่อด่วน ----
    $replyToken = $event['replyToken'] ?? '';
    
    if ($userId && $replyToken) {
        // 1. ลองดึงชื่อที่ Mapping ไว้ใน Database ก่อน
        $displayName = get_mapped_display_name_by_line_id($userId);
        
        // 2. ถ้าใน Database ไม่มีข้อมูล ให้ดึง Display Name จาก LINE Group API แทน
        if (!$displayName) {
            $displayName = get_line_group_member_profile($groupId, $userId);
        }
        
        if ($displayName) {
            // เพิ่มเข้าระบบ (ฟังก์ชันจะเช็กให้ว่าซ้ำหรือไม่)
            add_single_player_from_line($displayName);
            
            // สร้าง Template ข้อความสรุปรายชื่อปัจจุบันทั้งหมด ส่งกลับไปในกลุ่ม
            $summary_message = generate_line_summary_message();
            send_line_reply($replyToken, $summary_message);
        }
    }
} else if ($is_normal_update) {
    // ---- FLOW 2: ก๊อปปี้วางแบบเดิม ----
    $extracted_players = extract_players_from_text($text);

    file_put_contents('log.txt', '=== DEBUG ===' . PHP_EOL . PHP_EOL, FILE_APPEND);
    file_put_contents('log.txt', json_encode($extracted_players, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL, FILE_APPEND);
    
    // อัปเดต DB เงียบๆ ไม่ต้องตอบกลับ เพราะในแชทคือข้อมูลล่าสุดอยู่แล้ว
    sync_players_from_line($extracted_players);
}

// ส่ง Status 200 OK กลับให้ LINE
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'success']);