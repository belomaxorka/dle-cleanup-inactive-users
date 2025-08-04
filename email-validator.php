<?php
/**
 * ====================================================================
 * Очистка неактивных пользователей с мертвыми почтовыми ящиками
 *
 * @author belomaxorka
 * @version 1.0.0
 * @license MIT
 * @see https://github.com/belomaxorka/dle-cleanup-inactive-users
 * ====================================================================
 */

require_once __DIR__ . '/_dbconfig.php';

class SMTPEmailValidator
{
    private $db;
    private $logFile;
    private $logFileMaxSize = 10 * 1024 * 1024; // 10MB
    private $dryRun = true;
    private $timeout = 10;
    private $fromEmail = 'noreply@yourdomain.com';
    private $maxMxAttempts = 3; // Максимальное количество попыток подключения к MX серверам
    private $inactiveThreshold = 365 * 24 * 60 * 60;
    private $banDescription = 'Ваша учетная запись была деактивирована по причине длительного отсутствия на сайте, а также из-за подозрения в использовании временной/неосновной почты. <br>Если бан был выдан ошибочно, свяжитесь с любым из администраторов в соц. сетях.<br><br>Связь с нами: <ul><li><a target="_blank" href="#">Тут соц. сети...</a></li></ul>';

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->db = new mysqli(DBHOST, DBUSER, DBPASS, DBNAME);
        $this->logFile = $this->getLogFile();

        if ($this->db->connect_error) {
            die("Ошибка подключения к БД: " . $this->db->connect_error);
        }
    }

    /**
     * Получение имени лог-файла с ротацией при необходимости
     */
    private function getLogFile()
    {
        $baseName = __DIR__ . '/smtp_validation_' . date('Y-m-d_H-i-s');
        $logFile = $baseName . '.log';
        $counter = 1;

        // Проверяем существующие файлы и находим свободное имя
        while (file_exists($logFile) && filesize($logFile) >= $this->logFileMaxSize) {
            $logFile = $baseName . '_' . $counter . '.log';
            $counter++;
        }

        return $logFile;
    }

    /**
     * Получает MX записи для домена
     *
     * @param string $domain
     * @return array|false
     */
    private function getMXRecords($domain)
    {
        $mxRecords = [];
        if (getmxrr($domain, $mxRecords, $weights)) {
            // Сортируем по приоритету
            array_multisort($weights, $mxRecords);
            return $mxRecords;
        }
        return false;
    }

    /**
     * Проверяет email через SMTP с перебором MX серверов
     *
     * @param string $email
     * @return array
     */
    public function validateEmailSMTP($email)
    {
        $email = strtolower(trim($email));

        // Базовая проверка формата
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'reason' => 'invalid_format'];
        }

        list($localPart, $domain) = explode('@', $email);

        // Получаем MX записи
        $mxRecords = $this->getMXRecords($domain);
        if (!$mxRecords) {
            return ['valid' => false, 'reason' => 'no_mx_record'];
        }

        // Пробуем подключиться к MX серверам по очереди
        $attemptedServers = [];
        $lastError = 'no_connection_attempted';

        foreach ($mxRecords as $index => $mxHost) {
            if ($index >= $this->maxMxAttempts) {
                break;
            }

            $attemptedServers[] = $mxHost;
            $this->log("Пробуем MX сервер: $mxHost (попытка " . ($index + 1) . ")");

            $socket = @fsockopen($mxHost, 25, $errno, $errstr, $this->timeout);
            if (!$socket) {
                $lastError = "connection_failed: $errstr ($errno)";
                $this->log("Не удалось подключиться к $mxHost: $errstr ($errno)");
                continue;
            }

            $result = $this->performSMTPCheck($socket, $email, $mxHost);
            fclose($socket);

            if (isset($result['valid'])) {
                $result['attempted_servers'] = $attemptedServers;
                $result['used_server'] = $mxHost;
                return $result;
            }

            $lastError = isset($result['reason']) ? $result['reason'] : 'unknown_smtp_error';
        }

        return [
            'valid' => false,
            'reason' => $lastError,
            'attempted_servers' => $attemptedServers
        ];
    }

    /**
     * Выполняет SMTP диалог для проверки email
     *
     * @param $socket
     * @param string $email
     * @param $mxHost
     * @return array
     */
    private function performSMTPCheck($socket, $email, $mxHost)
    {
        // Читаем приветствие сервера
        $response = fgets($socket);
        if ($response === false) {
            return ['valid' => false, 'reason' => 'connection_dropped'];
        }
        if (substr($response, 0, 3) !== '220') {
            return ['valid' => false, 'reason' => 'smtp_error', 'response' => trim($response)];
        }

        // HELO
        fwrite($socket, "HELO " . gethostname() . "\r\n");
        $response = fgets($socket);
        if ($response === false) {
            return ['valid' => false, 'reason' => 'connection_dropped_helo'];
        }
        if (substr($response, 0, 3) !== '250') {
            return ['valid' => false, 'reason' => 'helo_failed', 'response' => trim($response)];
        }

        // MAIL FROM
        fwrite($socket, "MAIL FROM: <{$this->fromEmail}>\r\n");
        $response = fgets($socket);
        if ($response === false) {
            return ['valid' => false, 'reason' => 'connection_dropped_mailfrom'];
        }
        if (substr($response, 0, 3) !== '250') {
            return ['valid' => false, 'reason' => 'mail_from_failed', 'response' => trim($response)];
        }

        // RCPT TO - здесь и проверяется существование адреса
        fwrite($socket, "RCPT TO: <{$email}>\r\n");
        $response = fgets($socket);
        if ($response === false) {
            return ['valid' => false, 'reason' => 'connection_dropped_rcpt'];
        }

        $responseCode = substr($response, 0, 3);

        // QUIT
        fwrite($socket, "QUIT\r\n");

        // Анализируем ответ
        if ($responseCode === '250' || $responseCode === '251') {
            return ['valid' => true, 'reason' => 'valid', 'response' => trim($response)];
        } elseif (in_array($responseCode, ['550', '551', '553', '554', '540'])) {
            return ['valid' => false, 'reason' => 'user_unknown', 'response' => trim($response)];
        } else {
            return ['valid' => false, 'reason' => 'unknown_error', 'response' => trim($response)];
        }
    }

    /**
     * Проверяет батч email адресов
     *
     * @param int $limit
     * @param int $offset
     * @return int[]
     */
    public function validateEmailBatch($limit = 100, $offset = 0)
    {
        $inactiveTimestamp = time() - $this->inactiveThreshold;

        $query = "SELECT user_id, email, name, lastdate FROM " . PREFIX . "_users
                  WHERE email != ''
                    AND banned != 'yes'
                    AND lastdate < ?
                  ORDER BY user_id
                  LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("iii", $inactiveTimestamp, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $validCount = 0;
        $invalidCount = 0;
        $errorCount = 0;

        while ($user = $result->fetch_assoc()) {
            $this->log("Проверяем: {$user['email']} (ID: {$user['user_id']}, Последнее посещение: " . date('Y-m-d', $user['lastdate']) . ")");
            $validation = $this->validateEmailSMTP($user['email']);

            $serversInfo = isset($validation['attempted_servers']) ?
                'MX сервера: ' . implode(', ', $validation['attempted_servers']) : '';
            $usedServer = isset($validation['used_server']) ?
                'MX сервер использован: ' . $validation['used_server'] : '';

            if ($validation['valid']) {
                $this->log("✓ ВАЛИДНЫЙ: {$user['email']}");
                $validCount++;
            } else {
                $this->log("✗ НЕВАЛИДНЫЙ: {$user['email']} - {$validation['reason']}");

                if (in_array($validation['reason'], ['user_unknown', 'invalid_format'])) {
                    if (!$this->dryRun) {
                        $this->markEmailInvalid($user['user_id']);
                    }
                    $invalidCount++;
                } else {
                    $errorCount++;
                }
            }

            if (!empty($serversInfo)) {
                $this->log($serversInfo);
            }
            if (!empty($usedServer)) {
                $this->log($usedServer);
            }
            $this->log("--------------------------------------------------");

            // Пауза между проверками (важно!)
            usleep(500000); // 0.5 секунды
        }

        $result->free();
        $stmt->close();

        $this->log("");
        $this->log("=== РЕЗУЛЬТАТЫ БАТЧА ===");
        $this->log("Валидных: $validCount");
        $this->log("Невалидных: $invalidCount");
        $this->log("Ошибок проверки: $errorCount");
        $this->log("========================");

        // Удаляем кэш с банами
        if ($invalidCount > 0) {
            define('ENGINE_DIR', __DIR__ . '/engine');
            @unlink(ENGINE_DIR . '/cache/system/banned.php');
        }

        return [
            'valid' => $validCount,
            'invalid' => $invalidCount,
            'errors' => $errorCount
        ];
    }

    /**
     * Помечает email как невалидный
     *
     * @param int $user_id
     * @return void
     */
    private function markEmailInvalid($user_id)
    {
        // Меняем статус banned на yes
        $query = "UPDATE " . PREFIX . "_users SET banned = 'yes' WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 1) {
            $this->log("Поле `banned` помечено как `yes` для user_id = $user_id. Успех!");

            // Добавляем запись в таблицу dle_banned
            $query_banned = "INSERT INTO " . PREFIX . "_banned (users_id, descr, date, days) VALUES (?, ?, 0, 0)";
            $stmt_banned = $this->db->prepare($query_banned);
            $stmt_banned->bind_param("is", $user_id, $this->banDescription);

            if ($stmt_banned->execute()) {
                $this->log("Запись добавлена в таблицу " . PREFIX . "_banned для user_id = $user_id");
            } else {
                $this->log("Ошибка при добавлении записи в " . PREFIX . "_banned для user_id = $user_id: " . $stmt_banned->error);
            }
            $stmt_banned->close();

            // Удаляем запись в таблице dle_subscribe
            $query_subscribe = "DELETE FROM " . PREFIX . "_subscribe WHERE user_id = ?";
            $stmt_subscribe = $this->db->prepare($query_subscribe);
            $stmt_subscribe->bind_param("i", $user_id);

            if ($stmt_subscribe->execute()) {
                $deleted_rows = $stmt_subscribe->affected_rows;
                if ($deleted_rows > 0) {
                    $this->log("Удалено $deleted_rows записей из " . PREFIX . "_subscribe для user_id = $user_id");
                } else {
                    $this->log("Записей для удаления в " . PREFIX . "_subscribe не найдено для user_id = $user_id");
                }
            } else {
                $this->log("Ошибка при удалении записи из " . PREFIX . "_subscribe для user_id = $user_id: " . $stmt_subscribe->error);
            }
            $stmt_subscribe->close();
        } else {
            $this->log("Ошибка: не удалось обновить статус banned для user_id = $user_id. Затронуто строк: $affected");
        }
    }

    /**
     * Проверяет все email адреса порциями
     *
     * @param int $batchSize
     * @return void
     */
    public function validateAllEmails($batchSize = 50)
    {
        $inactiveTimestamp = time() - $this->inactiveThreshold;

        $totalQuery = "SELECT COUNT(*) as total FROM " . PREFIX . "_users
                       WHERE email != ''
                         AND banned != 'yes'
                         AND lastdate < ?";
        $stmt = $this->db->prepare($totalQuery);
        $stmt->bind_param("i", $inactiveTimestamp);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = $result->fetch_assoc()['total'];
        $stmt->close();
        $result->free();

        $this->log("Начинаем проверку $total email адресов батчами по $batchSize");
        $this->log("Неактивных (оффлайн): " . floor($this->inactiveThreshold / 86400) . " дней");

        $totalStats = ['valid' => 0, 'invalid' => 0, 'errors' => 0];

        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            $this->log("");
            $this->log("=== БАТЧ " . (floor($offset / $batchSize) + 1) . " (записи $offset - " . min($offset + $batchSize - 1, $total - 1) . ") ===");

            $batchStats = $this->validateEmailBatch($batchSize, $offset);

            $totalStats['valid'] += $batchStats['valid'];
            $totalStats['invalid'] += $batchStats['invalid'];
            $totalStats['errors'] += $batchStats['errors'];

            if (file_exists($this->logFile) && filesize($this->logFile) >= $this->logFileMaxSize) {
                $this->logFile = $this->getLogFile();
                $this->log("Создан новый лог-файл: " . basename($this->logFile));
            }

            // Пауза между батчами
            sleep(2);
        }

        $this->log("");
        $this->log("");
        $this->log("=== ОБЩИЕ РЕЗУЛЬТАТЫ ===");
        $this->log("Всего проверено: $total");
        $this->log("Валидных: {$totalStats['valid']}");
        $this->log("Невалидных: {$totalStats['invalid']}");
        $this->log("Ошибок: {$totalStats['errors']}");
        $this->log("========================");

        if ($this->dryRun) {
            $this->log("");
            $this->log("РЕЖИМ ТЕСТИРОВАНИЯ - изменения не применены!");
        }
    }

    /**
     * Проверяет один конкретный email
     *
     * @param string $email
     * @return array
     */
    public function testSingleEmail($email)
    {
        $this->log("Проверяем email: $email");
        $result = $this->validateEmailSMTP($email);

        $serversInfo = isset($result['attempted_servers']) ?
            'MX сервера: ' . implode(', ', $result['attempted_servers']) : '';
        $usedServer = isset($result['used_server']) ?
            'MX сервер использован: ' . $result['used_server'] : '';

        $this->log("Результат: " . ($result['valid'] ? 'ВАЛИДНЫЙ' : 'НЕВАЛИДНЫЙ') . " - {$result['reason']}");

        if (!empty($serversInfo)) {
            $this->log($serversInfo);
        }
        if (!empty($usedServer)) {
            $this->log($usedServer);
        }

        return $result;
    }

    /**
     * Включение production режима (БУДЬТЕ ОСТРОРОЖНЫ)
     *
     * @return void
     */
    public function enableRealMode()
    {
        $this->dryRun = false;
    }

    /**
     * Устанавливает почтовый адрес отправителя
     *
     * @param string $email
     * @return void
     */
    public function setFromEmail($email)
    {
        $this->fromEmail = $email;
    }

    /**
     * Устанавливает максимальное количество попыток подключения к MX серверам
     *
     * @param int $attempts
     * @return void
     */
    public function setMaxMxAttempts($attempts)
    {
        $this->maxMxAttempts = max(1, (int)$attempts);
    }

    /**
     * Устанавливает порог неактивности в днях
     *
     * @param int $days
     * @return void
     */
    public function setInactiveThresholdDays($days)
    {
        $this->inactiveThreshold = max(1, (int)$days) * 24 * 60 * 60;
    }

    /**
     * Логирование ошибок
     *
     * @param string $message
     * @return void
     */
    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        echo $logMessage;

        if (file_exists($this->logFile) && filesize($this->logFile) >= $this->logFileMaxSize) {
            $this->logFile = $this->getLogFile();
        }

        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
