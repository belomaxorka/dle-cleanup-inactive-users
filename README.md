## [DLE] Очистка неактивных пользователей с мертвыми почтовыми ящиками

### Установка:

Создаем экземпляр класса:

```php
require_once __DIR__ . '/email-validator.php';
$validator = new SMTPEmailValidator();
$validator->setFromEmail('noreply@yourdomain.com');
$validator->setInactiveThresholdDays(365);
```

### Примеры запуска:

Тест одного email:

```php
$validator->testSingleEmail('example@example.com');
```

Для проверки всех email (режим тестирования):

```php
$validator->validateAllEmails(20);
```

Для реального выполнения:

> [!WARNING]
> Обязательно сделайте резервную копию базы данных

```php
$validator->enableRealMode();
$validator->validateAllEmails(20);
```

### Использование:

> [!IMPORTANT]
> Запуск скрипта `test.php` осуществляется в CLI режиме!

1. Настраиваем параметры подключения в `_dbconfig.php`
2. Запускаем `test.php` с необходимыми параметрами
