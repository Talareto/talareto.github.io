<?php
/**
 * Wysyłka formularzy ze strony CanLead (wycena i kontakt) przez SMTP skrzynki biura.
 *
 * Ustawienia (serwer SMTP, hasło) są w osobnym pliku, który NIE trafia do repozytorium:
 *   1) canlead-config.php — katalog NAD public_html (najbezpieczniej), albo
 *   2) api/config.php — obok tego pliku (chroniony przez .htaccess).
 * Wzór pliku: config.example.php
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, bool $ok, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Konfiguracja ---------------------------------------------------------
$configPaths = [
    dirname(__DIR__, 2) . '/canlead-config.php',
    __DIR__ . '/config.php',
];
$config = null;
foreach ($configPaths as $path) {
    if (is_file($path)) {
        $config = require $path;
        break;
    }
}
if (!is_array($config)) {
    respond(500, false, 'Formularz nie jest jeszcze skonfigurowany. Napisz do nas bezpośrednio na e-mail.');
}

$cfg = array_merge([
    'smtp_host' => '',
    'smtp_port' => 465,
    'smtp_secure' => 'ssl',          // 'ssl' dla portu 465, 'tls' dla portu 587
    'smtp_user' => '',
    'smtp_pass' => '',
    'from_email' => '',
    'from_name' => 'Strona CanLead',
    'to_email' => '',
    'allowed_origins' => [],         // np. ['https://canlead.pl', 'https://www.canlead.pl']
    'autoreply' => false,            // true = klient dostaje automatyczne potwierdzenie
    'rate_limit' => 5,               // maks. zgłoszeń z jednego adresu IP…
    'rate_window' => 600,            // …w tylu sekundach
    'debug' => false,
], $config);

// --- Podstawowe zabezpieczenia ----------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, false, 'Niedozwolona metoda.');
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && $cfg['allowed_origins'] && !in_array($origin, $cfg['allowed_origins'], true)) {
    respond(403, false, 'Niedozwolone źródło zgłoszenia.');
}

$raw = file_get_contents('php://input', false, null, 0, 60000);
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}

// Pułapki na boty: ukryte pole i zbyt szybkie wysłanie — udajemy sukces, nic nie wysyłamy
if (!empty($input['website'])) {
    respond(200, true, 'OK');
}
$startedAt = isset($input['_t']) ? (int) $input['_t'] : 0;
if ($startedAt > 0 && (time() * 1000 - $startedAt) < 3000) {
    respond(200, true, 'OK');
}

// Limit zgłoszeń z jednego adresu IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = sys_get_temp_dir() . '/canlead_rate_' . hash('sha256', $ip);
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $hits = array_filter(
        array_map('intval', explode(',', (string) file_get_contents($rateFile))),
        fn ($t) => $t > $now - (int) $cfg['rate_window']
    );
}
if (count($hits) >= (int) $cfg['rate_limit']) {
    respond(429, false, 'Wysłano zbyt wiele zgłoszeń. Spróbuj ponownie za kilka minut.');
}

// --- Pola formularzy ------------------------------------------------------
$forms = [
    'wycena' => [
        'subject' => 'Zapytanie o wycenę',
        'required' => ['imie_nazwisko', 'email', 'telefon', 'miasto', 'kod_pocztowy', 'forma_dzialalnosci',
            'rodzaj_dzialalnosci', 'vat', 'dotacje', 'leasingi', 'srodki_trwale', 'liczba_pracownikow',
            'dokumenty_zakupowe_mies', 'dokumenty_sprzedazowe_mies'],
        'fields' => [
            'pakiet' => 'Pakiet',
            'imie_nazwisko' => 'Imię i nazwisko',
            'email' => 'E-mail',
            'telefon' => 'Telefon',
            'miasto' => 'Miasto',
            'kod_pocztowy' => 'Kod pocztowy',
            'forma_dzialalnosci' => 'Forma działalności',
            'nip' => 'NIP',
            'firma_w_zakladaniu' => 'Firma w trakcie zakładania',
            'rodzaj_dzialalnosci' => 'Przeważający rodzaj działalności',
            'vat' => 'Czynny podatnik VAT',
            'dotacje' => 'Rozlicza dotacje',
            'leasingi' => 'Leasingi',
            'srodki_trwale' => 'Środki trwałe',
            'liczba_wspolnikow' => 'Liczba udziałowców / wspólników',
            'liczba_pracownikow' => 'Liczba pracowników',
            'dokumenty_zakupowe_mies' => 'Dokumenty zakupowe / mies.',
            'dokumenty_sprzedazowe_mies' => 'Dokumenty sprzedażowe / mies.',
            'uwagi' => 'Dodatkowe informacje',
        ],
    ],
    'kontakt' => [
        'subject' => 'Wiadomość ze strony',
        'required' => ['imie_nazwisko', 'email', 'wiadomosc'],
        'fields' => [
            'imie_nazwisko' => 'Imię i nazwisko',
            'email' => 'E-mail',
            'telefon' => 'Telefon',
            'wiadomosc' => 'Wiadomość',
        ],
    ],
];

$type = (string) ($input['type'] ?? '');
if (!isset($forms[$type])) {
    respond(400, false, 'Nieznany formularz.');
}
$form = $forms[$type];

$clean = function ($value, int $max): string {
    $value = is_scalar($value) ? (string) $value : '';
    $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';   // bez znaków sterujących
    $value = trim($value);
    return mb_substr($value, 0, $max);
};

$data = [];
foreach ($form['fields'] as $key => $label) {
    $max = in_array($key, ['uwagi', 'wiadomosc'], true) ? 5000 : 300;
    $v = $clean($input[$key] ?? '', $max);
    if ($v !== '') {
        $data[$key] = $v;
    }
}

foreach ($form['required'] as $key) {
    if (!isset($data[$key])) {
        respond(422, false, 'Uzupełnij wszystkie wymagane pola.');
    }
}
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    respond(422, false, 'Adres e-mail wygląda na niepoprawny.');
}
if ($type === 'wycena' && empty($data['nip']) && empty($data['firma_w_zakladaniu'])) {
    respond(422, false, 'Podaj NIP albo zaznacz, że dopiero zakładasz firmę.');
}

// --- Treść wiadomości -----------------------------------------------------
$name = $data['imie_nazwisko'];
$subject = $form['subject'] . (isset($data['pakiet']) ? ': ' . $data['pakiet'] : '') . ' — ' . $name;
$subject = mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 200);

$esc = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$rowsHtml = '';
$text = $form['subject'] . "\n\n";
foreach ($form['fields'] as $key => $label) {
    if (!isset($data[$key])) {
        continue;
    }
    $rowsHtml .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #e6e8ec;color:#5b6270;white-space:nowrap;vertical-align:top">'
        . $esc($label) . '</td><td style="padding:8px 12px;border-bottom:1px solid #e6e8ec;color:#1d2433">'
        . nl2br($esc($data[$key])) . '</td></tr>';
    $text .= $label . ': ' . $data[$key] . "\n";
}
$sentAt = date('d.m.Y H:i');
$html = '<div style="font-family:Arial,sans-serif;font-size:14px;max-width:640px">'
    . '<h2 style="color:#1d2433;margin:0 0 4px">' . $esc($form['subject']) . '</h2>'
    . '<p style="color:#5b6270;margin:0 0 16px">Wysłano ze strony ' . $esc($sentAt) . '. Odpowiedz na tego maila, aby napisać do klienta.</p>'
    . '<table style="border-collapse:collapse;width:100%">' . $rowsHtml . '</table></div>';
$text .= "\nWysłano ze strony: " . $sentAt;

// --- Wysyłka ----------------------------------------------------------------
function makeMailer(array $cfg): PHPMailer
{
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = $cfg['smtp_host'];
    $m->Port = (int) $cfg['smtp_port'];
    $m->SMTPAuth = true;
    $m->Username = $cfg['smtp_user'];
    $m->Password = $cfg['smtp_pass'];
    if ($cfg['smtp_secure'] === 'ssl') {
        $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($cfg['smtp_secure'] === 'tls') {
        $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $m->SMTPSecure = '';
        $m->SMTPAutoTLS = false;
    }
    $m->CharSet = 'UTF-8';
    $m->Timeout = 15;
    $m->setFrom($cfg['from_email'] ?: $cfg['smtp_user'], $cfg['from_name']);
    return $m;
}

try {
    $mail = makeMailer($cfg);
    $mail->addAddress($cfg['to_email'] ?: $cfg['smtp_user']);
    $mail->addReplyTo($data['email'], $name);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $html;
    $mail->AltBody = $text;
    $mail->send();
} catch (MailException $e) {
    error_log('[canlead-form] ' . $e->getMessage());
    $msg = 'Nie udało się wysłać wiadomości. Spróbuj ponownie albo napisz do nas bezpośrednio.';
    if (!empty($cfg['debug'])) {
        $msg .= ' (' . $e->getMessage() . ')';
    }
    respond(502, false, $msg);
}

$hits[] = $now;
@file_put_contents($rateFile, implode(',', $hits), LOCK_EX);

// Opcjonalne potwierdzenie dla klienta
if (!empty($cfg['autoreply'])) {
    try {
        $reply = makeMailer($cfg);
        $reply->addAddress($data['email'], $name);
        $reply->Subject = 'Otrzymaliśmy Twoje zgłoszenie — CanLead';
        $reply->Body = "Dzień dobry,\n\ndziękujemy za wiadomość. Odezwiemy się w ciągu jednego dnia roboczego.\n\nZespół CanLead\n"
            . ($cfg['to_email'] ?: $cfg['smtp_user']);
        $reply->send();
    } catch (MailException $e) {
        error_log('[canlead-form autoreply] ' . $e->getMessage());
    }
}

respond(200, true, 'Dziękujemy! Wiadomość została wysłana.');
