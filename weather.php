<?php
/* ============================================================
   기상청 초단기실황 중계기  —  weather.php
   ------------------------------------------------------------
   1) data.go.kr 에서 "기상청_단기예보 조회서비스" 활용신청
   2) 발급받은 "일반 인증키(Decoding)" 를 아래 $KEY 에 붙여넣기
   3) 이 파일을 HTTPS 되는 서버에 업로드
   4) 브라우저에서 아래 주소로 확인
      https://내도메인/weather.php?lat=35.82&lon=127.11
   ============================================================ */

$KEY = 'HPuZ7H0DD8pwq7K5%2FiuJfIBr4Tn%2BNbojQl30TIiv%2BLrnz1SKC%2BuoY1UY7ry1PXJvK6xd16FVPiadC5X%2FToyJ3w%3D%3D';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

function out($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : 0;
$lon = isset($_GET['lon']) ? floatval($_GET['lon']) : 0;
if ($lat < 32 || $lat > 44 || $lon < 123 || $lon > 133) {
    out(['error' => '한국 밖 좌표입니다'], 400);
}
if (strpos($KEY, '여기에') === 0) {
    out(['error' => 'API 키를 아직 넣지 않았습니다'], 500);
}

/* ---- 위경도 → 기상청 격자(nx, ny) : 기상청 제공 변환식 ---- */
function dfs_xy_conv($lat, $lon) {
    $RE = 6371.00877; $GRID = 5.0;
    $SLAT1 = 30.0; $SLAT2 = 60.0;
    $OLON = 126.0; $OLAT = 38.0;
    $XO = 43; $YO = 136;

    $DEGRAD = M_PI / 180.0;
    $re = $RE / $GRID;
    $slat1 = $SLAT1 * $DEGRAD; $slat2 = $SLAT2 * $DEGRAD;
    $olon  = $OLON  * $DEGRAD; $olat  = $OLAT  * $DEGRAD;

    $sn = tan(M_PI * 0.25 + $slat2 * 0.5) / tan(M_PI * 0.25 + $slat1 * 0.5);
    $sn = log(cos($slat1) / cos($slat2)) / log($sn);
    $sf = tan(M_PI * 0.25 + $slat1 * 0.5);
    $sf = pow($sf, $sn) * cos($slat1) / $sn;
    $ro = tan(M_PI * 0.25 + $olat * 0.5);
    $ro = $re * $sf / pow($ro, $sn);

    $ra = tan(M_PI * 0.25 + $lat * $DEGRAD * 0.5);
    $ra = $re * $sf / pow($ra, $sn);
    $theta = $lon * $DEGRAD - $olon;
    if ($theta >  M_PI) $theta -= 2.0 * M_PI;
    if ($theta < -M_PI) $theta += 2.0 * M_PI;
    $theta *= $sn;

    return [
        'nx' => (int)floor($ra * sin($theta) + $XO + 0.5),
        'ny' => (int)floor($ro - $ra * cos($theta) + $YO + 0.5),
    ];
}

$g = dfs_xy_conv($lat, $lon);

/* ---- 발표 시각 : 매시 40분 발표, 여유를 두고 45분 기준 ---- */
$ts = time();
if ((int)date('i', $ts) < 45) $ts -= 3600;
$base_date = date('Ymd', $ts);
$base_time = date('H', $ts) . '00';

/* ---- 5분 캐시 (같은 격자·같은 발표시각이면 재사용) ---- */
$cacheFile = sys_get_temp_dir() . "/kma_{$g['nx']}_{$g['ny']}_{$base_date}{$base_time}.json";
if (is_readable($cacheFile) && (time() - filemtime($cacheFile) < 300)) {
    echo file_get_contents($cacheFile);
    exit;
}

$url = 'http://apis.data.go.kr/1360000/VilageFcstInfoService_2.0/getUltraSrtNcst'
     . '?serviceKey=' . rawurlencode($KEY)
     . '&pageNo=1&numOfRows=100&dataType=JSON'
     . '&base_date=' . $base_date
     . '&base_time=' . $base_time
     . '&nx=' . $g['nx'] . '&ny=' . $g['ny'];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($raw === false) out(['error' => '기상청 접속 실패: ' . $err], 502);

$json = json_decode($raw, true);
$items = $json['response']['body']['items']['item'] ?? null;
if (!$items) {
    $msg = $json['response']['header']['resultMsg'] ?? '응답 형식 오류';
    out(['error' => '기상청: ' . $msg, 'raw' => mb_substr($raw, 0, 200)], 502);
}

$v = [];
foreach ($items as $it) $v[$it['category']] = $it['obsrValue'];

$temp = isset($v['T1H']) ? floatval($v['T1H']) : null;   // 기온
$hum  = isset($v['REH']) ? intval($v['REH'])   : null;   // 습도
$rain = isset($v['RN1']) ? floatval($v['RN1']) : 0;      // 1시간 강수량
$wind = isset($v['WSD']) ? floatval($v['WSD']) : 0;      // 풍속

/* ---- 체감온도 (여름 열지수 / 겨울 바람냉각) ---- */
$feel = $temp;
if ($temp !== null && $hum !== null) {
    if ($temp >= 27) {
        $t = $temp; $h = $hum;
        $feel = -0.2442 + 0.55399 * ($t * sqrt(0.00391838 * pow($h, 1.5) + 0.0227))
              + 0.45535 * $t - 0.00238 * pow($t, 2) - 0.000843 * pow($t, 2) * $h;
    } elseif ($temp <= 10 && $wind >= 1.3) {
        $w = pow($wind * 3.6, 0.16);
        $feel = 13.12 + 0.6215 * $temp - 11.37 * $w + 0.3965 * $temp * $w;
    }
    $feel = round($feel, 1);
}

$result = json_encode([
    'temp'     => $temp,
    'feel'     => $feel,
    'humidity' => $hum,
    'rain'     => $rain,
    'wind'     => $wind,
    'base'     => $base_date . ' ' . substr($base_time, 0, 2) . ':00',
    'nx'       => $g['nx'],
    'ny'       => $g['ny'],
    'source'   => '기상청 실측',
], JSON_UNESCAPED_UNICODE);

@file_put_contents($cacheFile, $result);
echo $result;
