<?php
/** Sinov uchun soxta Telegram serveri: php -S 127.0.0.1:PORT tests/fake_telegram.php  (TG_LOG — so'rovlar yoziladigan fayl) */
$log = getenv('TG_LOG') ?: sys_get_temp_dir() . '/fake_telegram.log';
if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/bot')) { // faqat Bot API so'rovlari (tayyorlikni tekshirish so'rovlari yozilmaydi)
    file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'] ?? '', 'post' => $_POST], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json');
echo '{"ok":true,"result":{"message_id":1}}';
