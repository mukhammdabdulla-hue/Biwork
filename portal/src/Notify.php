<?php
declare(strict_types=1);

/**
 * Yangi murojaat haqida xabar (ixtiyoriy). Telegram bot: config.php da
 *   'notify' => ['telegram_token' => '123:ABC', 'telegram_chat' => '-100123456789']
 * Sozlanmagan bo'lsa hech narsa qilmaydi; xato bo'lsa murojaat saqlanadi, xato data/error.log ga yoziladi.
 */
final class Notify
{
    public static function lead(array $lead, ?array $tool): void
    {
        $cfg = Config::raw('notify', []);
        $token = is_array($cfg) ? (string)($cfg['telegram_token'] ?? '') : '';
        $chat = is_array($cfg) ? (string)($cfg['telegram_chat'] ?? '') : '';
        if ($token === '' || $chat === '' || !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
            return;
        }
        $api = rtrim((string)($cfg['telegram_api'] ?? 'https://api.telegram.org'), '/');
        $text = "Yangi murojaat" . ($tool ? ' — ' . $tool['name'] : '') . "\n"
            . 'Ism: ' . $lead['name'] . "\n"
            . ($lead['company'] !== '' ? 'Korxona: ' . $lead['company'] . "\n" : '')
            . ($lead['phone'] !== '' ? 'Telefon: ' . $lead['phone'] . "\n" : '')
            . ($lead['email'] !== '' ? 'Email: ' . $lead['email'] . "\n" : '')
            . ($lead['message'] !== '' ? 'Izoh: ' . mb_substr($lead['message'], 0, 400) . "\n" : '')
            . ($lead['source'] !== '' ? 'Manba: ' . $lead['source'] . "\n" : '')
            . Util::absUrl('/admin/leads');
        try {
            $ch = curl_init($api . '/bot' . $token . '/sendMessage');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true'],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4]);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($res === false || $code !== 200) {
                throw new RuntimeException('Telegram javobi: HTTP ' . $code);
            }
        } catch (Throwable $e) {
            @file_put_contents(Config::dataDir() . '/error.log', date('c') . ' Notify: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}
