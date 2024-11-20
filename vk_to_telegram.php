<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define("BX_CRONTAB", true);
define('BX_NO_ACCELERATOR_RESET', true);

// Укажите корректный путь к DOCUMENT_ROOT
$_SERVER['DOCUMENT_ROOT'] = '/home/ant3725595/borodulin.expert/docs';

// Подключите пролог Битрикс
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

//запуск ядра Битрикс
/*
Этот скрипт:
Получает последние посты из ВКонтакте через API, включая текст, изображения, видео и музыку.
Проверяет, какие посты ещё не были отправлены, используя файл для хранения ID последнего обработанного поста.
Отправляет новый контент в Telegram, включая:
Текст (с ссылкой на пост ВКонтакте).
Изображения (максимального качества).
Видео (ссылкой на пост в ВКонтакте).
Музыку (аудиофайлы с названием и исполнителем).
Обновляет ID последнего отправленного поста, чтобы избежать дублирования. */

// Конфигурация
$vkAccessToken = '________'; // Токен для доступа к API ВКонтакте
$telegramToken = '_____'; // Токен для доступа к API Telegram
$telegramChatId = '____'; // ID Telegram-канала или чата
$vkGroupId = '___'; // Укажите ID вашей группы
$lastPostIdFile = __DIR__ . '/last_post_id.txt'; // Файл для хранения ID последнего отправленного поста

// Функция для получения постов из ВКонтакте
function getPosts($vkAccessToken, $vkGroupId, $count = 5) {
    $url = "https://api.vk.com/method/wall.get?owner_id=$vkGroupId&count=$count&access_token=$vkAccessToken&v=5.131";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    return $data['response']['items'] ?? [];
}

// Функция для отправки фото с текстом в Telegram
function sendPhotoToTelegram($telegramToken, $chatId, $photoUrl, $caption) {
    $url = "https://api.telegram.org/bot$telegramToken/sendPhoto";

    // Формируем запрос с помощью cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $chatId,
        'photo' => $photoUrl,
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ]);

    // Отправляем запрос и получаем ответ
    $response = curl_exec($ch);
    curl_close($ch);

    // Логируем ответ для отладки
    file_put_contents(__DIR__ . '/telegram_photo_log.txt', $response . "\n", FILE_APPEND);
    return $response;
}

// Основная логика
// Чтение последнего ID отправленного поста
$lastPostId = file_exists($lastPostIdFile) ? file_get_contents($lastPostIdFile) : 0;

// Получение новых постов
$posts = getPosts($vkAccessToken, $vkGroupId);

if (empty($posts)) {
    echo "Нет новых постов\n";
    exit;
}

// Обработка новых постов
foreach (array_reverse($posts) as $post) {
    // Пропуск постов, которые уже отправлены
    if ($post['id'] <= $lastPostId) {
        continue;
    }

    // Пропуск закреплённых постов
    if (isset($post['is_pinned']) && $post['is_pinned'] == 1) {
        continue;
    }

    // Формирование текста поста
    $text = "<b>ППО \"Первый независимый профсоюз\"</b> ✊\n";
    $text .= !empty($post['text']) ? $post['text'] : 'Текст отсутствует';

    // Добавляем ссылку на пост ВКонтакте
    $postLink = "https://vk.com/wall" . $vkGroupId . "_" . $post['id'];
    $text .= "\n\n<a href=\"$postLink\">Ссылка на пост ВКонтакте</a>";

    // Лог отправляемого текста
    file_put_contents(__DIR__ . '/debug_data_log.txt', "Text: $text\n", FILE_APPEND);

    // Обработка вложений (только фото)
    $photoSent = false;
    if (!empty($post['attachments'])) {
        foreach ($post['attachments'] as $attachment) {
            if ($attachment['type'] == 'photo') {
                $photoUrl = end($attachment['photo']['sizes'])['url']; // Берём изображение максимального качества
                
                // Используем функцию отправки фото с текстом
                sendPhotoToTelegram($telegramToken, $telegramChatId, $photoUrl, $text);
                $photoSent = true;
                break; // Отправляем только одно изображение
            }
        }
    }

    // Если изображение не найдено, отправляем только текст с ссылкой на пост
    if (!$photoSent) {
        $url = "https://api.telegram.org/bot$telegramToken/sendMessage";
        file_get_contents($url . '?' . http_build_query([
            'chat_id' => $telegramChatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ]));
    }

    // Обновление ID последнего отправленного поста
    file_put_contents($lastPostIdFile, $post['id']);
}

echo "Новые посты отправлены\n";

