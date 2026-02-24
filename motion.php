<?php
header('Content-Type: application/json');

$host   = "localhost";
$dbname = "ghost_sensor";
$user   = "root";
$pass   = "";

try {
  $pdo = new PDO(
    "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
    $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch(PDOException $e) {
  http_response_code(500);
  exit(json_encode(['status'=>'error','msg'=>$e->getMessage()]));
}

$sensor      = $_GET['sensor']      ?? '';
$side        = $_GET['side']        ?? '';
$motion      = isset($_GET['motion'])      ? intval($_GET['motion'])      : -1;
$pos_x       = isset($_GET['pos_x'])       ? floatval($_GET['pos_x'])     : null;
$pos_y       = isset($_GET['pos_y'])       ? floatval($_GET['pos_y'])     : null;
$rssi1       = isset($_GET['rssi1'])       ? intval($_GET['rssi1'])       : null;
$rssi2       = isset($_GET['rssi2'])       ? intval($_GET['rssi2'])       : null;
$peer1       = $_GET['peer1']       ?? '';
$peer2       = $_GET['peer2']       ?? '';
$duration    = isset($_GET['duration'])    ? intval($_GET['duration'])    : 0;
$count       = isset($_GET['count'])       ? intval($_GET['count'])       : 0;
$rssi_router = isset($_GET['rssi_router']) ? intval($_GET['rssi_router']) : null;

$validSensors = ['S1','S2','S3'];
$validSides   = ['LEFT','RIGHT','CENTER'];

if (!in_array($sensor, $validSensors)) { http_response_code(400); exit(json_encode(['status'=>'error','msg'=>'Invalid sensor'])); }
if (!in_array($side,   $validSides))   { http_response_code(400); exit(json_encode(['status'=>'error','msg'=>'Invalid side'])); }
if ($motion !== 0 && $motion !== 1)    { http_response_code(400); exit(json_encode(['status'=>'error','msg'=>'Invalid motion'])); }

// Map RSSI to correct columns
$rssi_s1 = null; $rssi_s2 = null; $rssi_s3 = null;
if ($sensor=='S1') $rssi_s1=0;
if ($sensor=='S2') $rssi_s2=0;
if ($sensor=='S3') $rssi_s3=0;
if ($peer1=='S1') $rssi_s1=$rssi1;
if ($peer1=='S2') $rssi_s2=$rssi1;
if ($peer1=='S3') $rssi_s3=$rssi1;
if ($peer2=='S1') $rssi_s1=$rssi2;
if ($peer2=='S2') $rssi_s2=$rssi2;
if ($peer2=='S3') $rssi_s3=$rssi2;

// Prevent duplicate
$stmt = $pdo->prepare("SELECT motion FROM motion_tracking WHERE sensor_id=? ORDER BY recorded_at DESC LIMIT 1");
$stmt->execute([$sensor]);
$last = $stmt->fetch(PDO::FETCH_ASSOC);
if ($last && intval($last['motion'])===$motion) {
  exit(json_encode(['status'=>'duplicate']));
}

// Insert
$stmt = $pdo->prepare("
  INSERT INTO motion_tracking
  (sensor_id, side, motion, pos_x, pos_y,
   rssi_s1, rssi_s2, rssi_s3,
   duration, count, rssi_router, recorded_at)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP(6))
");
$stmt->execute([$sensor,$side,$motion,$pos_x,$pos_y,
                $rssi_s1,$rssi_s2,$rssi_s3,
                $duration,$count,$rssi_router]);

$id = $pdo->lastInsertId();

// =============================================
// NOTIFY WebSocket SERVER — non-blocking!
// =============================================
$wsUrl  = "http://localhost:8080/notify";
$wsUrl .= "?sensor="   . urlencode($sensor);
$wsUrl .= "&side="     . urlencode($side);
$wsUrl .= "&motion="   . $motion;
$wsUrl .= "&pos_x="    . ($pos_x  ?? 0);
$wsUrl .= "&pos_y="    . ($pos_y  ?? 0);
$wsUrl .= "&duration=" . $duration;
$wsUrl .= "&count="    . $count;
$wsUrl .= "&rssi="     . ($rssi_router ?? 0);

// Send but don't wait for response (non-blocking)
$ctx = stream_context_create([
  'http' => [
    'timeout'        => 0.05, // 50ms max — don't slow down ESP32!
    'ignore_errors'  => true,
  ]
]);
@file_get_contents($wsUrl, false, $ctx);
// =============================================

echo json_encode([
  'status'      => 'ok',
  'id'          => $id,
  'sensor'      => $sensor,
  'side'        => $side,
  'motion'      => $motion,
  'duration'    => $duration,
  'count'       => $count,
  'rssi_router' => $rssi_router
]);
?>
