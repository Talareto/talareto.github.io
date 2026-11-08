<?php
/**
 * WZÓR ustawień wysyłki formularzy. NIE wpisuj tu prawdziwego hasła.
 *
 * Jak użyć:
 *   1. Skopiuj ten plik jako  canlead-config.php  do katalogu NAD public_html
 *      (np. /home/twoje-konto/canlead-config.php) — wtedy nikt nie pobierze go przez przeglądarkę.
 *      Jeśli hosting na to nie pozwala, zapisz go jako  api/config.php  (obok wyslij.php).
 *   2. Uzupełnij dane skrzynki biuro@canlead.pl. Serwer SMTP i port znajdziesz w panelu hostingu,
 *      w ustawieniach poczty (często: mail.canlead.pl albo adres serwera dostawcy, port 465 + SSL).
 *   3. Nigdy nie dodawaj pliku z hasłem do repozytorium na GitHubie.
 */

return [
    'smtp_host'   => 'mail.canlead.pl',     // serwer SMTP z panelu hostingu
    'smtp_port'   => 465,                    // 465 (SSL) albo 587 (TLS)
    'smtp_secure' => 'ssl',                  // 'ssl' dla 465, 'tls' dla 587
    'smtp_user'   => 'biuro@canlead.pl',     // login do skrzynki = pełny adres
    'smtp_pass'   => 'TU-WPISZ-HASLO',       // hasło do skrzynki

    'from_email'  => 'biuro@canlead.pl',     // nadawca (ta sama skrzynka)
    'from_name'   => 'Strona CanLead',
    'to_email'    => 'biuro@canlead.pl',     // gdzie mają trafiać zgłoszenia

    // Adresy, z których formularz może wysyłać (puste = bez sprawdzania)
    'allowed_origins' => ['https://canlead.pl', 'https://www.canlead.pl'],

    'autoreply'   => false,                  // true = klient dostaje automatyczne potwierdzenie
    'debug'       => false,                  // true tylko na chwilę, gdy coś nie działa (pokazuje błąd SMTP)
];
