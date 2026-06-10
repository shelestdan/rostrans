<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function value(string $key, int $maxLength): string
{
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) {
        return '';
    }

    $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    if (mb_strlen($text, 'UTF-8') > $maxLength) {
        $text = mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return $text;
}

function limited(string $ip): bool
{
    $now = time();
    $window = 600;
    $maxRequests = 5;
    $key = hash('sha256', $ip);
    $file = sys_get_temp_dir() . '/rutrans-leads-' . hash('sha256', __DIR__) . '.json';
    $handle = @fopen($file, 'c+');

    if ($handle === false) {
        error_log('RUTRANS lead rate file is unavailable');
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        $raw = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        foreach ($data as $hash => $times) {
            if (!is_array($times)) {
                unset($data[$hash]);
                continue;
            }
            $data[$hash] = array_values(array_filter($times, static fn ($time) => is_int($time) && $time > $now - $window));
            if ($data[$hash] === []) {
                unset($data[$hash]);
            }
        }

        $times = $data[$key] ?? [];
        if (count($times) >= $maxRequests) {
            return true;
        }

        $times[] = $now;
        $data[$key] = $times;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES));

        return false;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Метод не поддерживается']);
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) {
    respond(413, ['ok' => false, 'message' => 'Слишком большой запрос']);
}

if (value('website', 200) !== '') {
    respond(200, ['ok' => true]);
}

$name = value('name', 80);
$phone = value('phone', 120);
$company = value('company', 120);
$cargo = value('cargo', 1000);
$route = value('route', 180);
$cargoDetails = value('cargo_details', 500);
$consent = value('consent', 10);

if ($cargo === '' && ($route !== '' || $cargoDetails !== '')) {
    $cargo = trim("Маршрут: {$route}\nГруз: {$cargoDetails}");
}

if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($phone, 'UTF-8') < 6 || mb_strlen($cargo, 'UTF-8') < 5) {
    respond(422, ['ok' => false, 'message' => 'Заполните имя, контакт и описание заявки']);
}

if ($consent !== 'yes') {
    respond(422, ['ok' => false, 'message' => 'Подтвердите согласие на обработку данных']);
}

if (preg_match('/[\r\n<>{}]/u', $phone)) {
    respond(422, ['ok' => false, 'message' => 'Проверьте контакт']);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (limited($ip)) {
    respond(429, ['ok' => false, 'message' => 'Слишком много заявок. Попробуйте позже']);
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    error_log('RUTRANS config.php is missing');
    respond(500, ['ok' => false, 'message' => 'Сервис заявок временно недоступен']);
}

$config = require $configPath;
if (!is_array($config)) {
    error_log('RUTRANS config.php must return array');
    respond(500, ['ok' => false, 'message' => 'Сервис заявок временно недоступен']);
}

$apiKey = (string)($config['resend_api_key'] ?? '');
$fromEmail = (string)($config['from_email'] ?? '');
$toEmail = (string)($config['to_email'] ?? 'rutrans.psarev@mail.ru');

if ($apiKey === '' || $fromEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    error_log('RUTRANS mail config is incomplete');
    respond(500, ['ok' => false, 'message' => 'Сервис заявок временно недоступен']);
}

$safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safePhone = htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeCompany = htmlspecialchars($company !== '' ? $company : 'Не указана', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeCargo = nl2br(htmlspecialchars($cargo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

$html = <<<HTML
<h2>Заявка с сайта РУТРАНС</h2>
<p><strong>Имя:</strong> {$safeName}</p>
<p><strong>Контакт:</strong> {$safePhone}</p>
<p><strong>Компания:</strong> {$safeCompany}</p>
<p><strong>Маршрут и груз:</strong><br>{$safeCargo}</p>
HTML;

$payload = [
    'from' => 'RUTRANS Site <' . $fromEmail . '>',
    'to' => [$toEmail],
    'subject' => 'Заявка с сайта РУТРАНС',
    'html' => $html
];

if (!function_exists('curl_init')) {
    error_log('RUTRANS curl extension is unavailable');
    respond(500, ['ok' => false, 'message' => 'Сервис заявок временно недоступен']);
}

$ch = curl_init('https://api.resend.com/emails');
if ($ch === false) {
    error_log('RUTRANS curl init failed');
    respond(500, ['ok' => false, 'message' => 'Сервис заявок временно недоступен']);
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 12
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {
    error_log('RUTRANS resend failed: HTTP ' . $httpCode . ' ' . $curlError);
    respond(502, ['ok' => false, 'message' => 'Не удалось отправить заявку']);
}

respond(200, ['ok' => true]);
