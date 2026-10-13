# Криптовалютный платёжный шлюз для Paymenter: USDT TRC20 через SnapEX Pay

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Paymenter](https://img.shields.io/badge/Paymenter-v1.x-blue.svg)](https://github.com/Paymenter/Paymenter)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4.svg)](https://www.php.net/)
[![USDT](https://img.shields.io/badge/USDT-TRC20%20%7C%20ERC20%20%7C%20BEP20-26a17b.svg)](https://pay.snapex.pro)

[English version](README.md)

Расширение-шлюз для [Paymenter](https://github.com/Paymenter/Paymenter), через которое клиенты оплачивают счета в **USDT (TRC20, ERC20, BEP20)** и других монетах с помощью [SnapEX Pay](https://snapex.pro). Когда клиент нажимает «Оплатить», расширение создаёт счёт SnapEX Pay и переводит клиента на страницу оплаты. После подтверждения платежа подписанный вебхук отмечает счёт в Paymenter как оплаченный. Свой кошелёк, ноду или мониторинг блокчейна поднимать не нужно. Деньги поступают на мерчант-баланс SnapEX Pay.

> **Подключаетесь к SnapEX Pay?** Мерчант-аккаунт, тарифы и все готовые модули — на **[pay.snapex.pro](https://pay.snapex.pro)**.

## Возможности

- Штатное расширение Paymenter v1 (`Paymenter\Extensions\Gateways\SnapexPay`). Появляется в **Admin → Gateways**.
- Страница оплаты SnapEX Pay: USDT в сетях TRC20, ERC20 и BEP20, а также другие монеты, которые там доступны.
- Вебхук проверяется по HMAC-SHA256 (`Webhook-Signature`) со сравнением за постоянное время. Если метка времени расходится больше чем на 5 минут, запрос отклоняется.
- Телу вебхука расширение не доверяет. Перед зачислением оно перечитывает счёт из API SnapEX вашим ключом.
- Платёж записывается один раз. Повторный вебхук не создаёт вторую транзакцию и не меняет первую.
- В Paymenter зачисляется ровно та сумма, которая была выставлена, в валюте счёта.
- Несколько сайтов на Paymenter могут работать с одним аккаунтом SnapEX: каждый закрывает только свои счета.
- В тестовом режиме счета подтверждаются без реальных денег. Тестовые оплаты игнорируются, пока шлюз в режиме `live`.
- Фиксированный курс к USD для магазинов, которые выставляют счета не в долларах.

## Как это работает

1. Клиент открывает неоплаченный счёт и выбирает **SnapEX Pay**.
2. Расширение вызывает `POST /api/v1/merchant/invoices` с суммой в USD, `Idempotency-Key` и `external_id`, по которому определяются сайт и счёт. Затем перенаправляет клиента на полученный `pay_url`.
3. Клиент платит и возвращается на страницу счёта. Paymenter сам проверяет статус (`?checkPayment=true`).
4. SnapEX Pay отправляет `invoice.paid` на `https://<ваш-paymenter>/extensions/snapexpay/webhook`.
5. Расширение проверяет подпись, запрашивает `GET /api/v1/merchant/invoices/{id}` и вызывает `ExtensionHelper::addPayment()` только при статусе `paid`. Номер транзакции — id счёта SnapEX (`INV-…`).

Двойной клик в течение одной минуты даёт тот же счёт SnapEX. Если клиент попробует позже, например после истечения срока оплаты, создаётся новый счёт.

## Требования

- Paymenter v1.x (PHP 8.3+)
- Мерчант-аккаунт [SnapEX Pay](https://pay.snapex.pro)
- Сайт Paymenter, доступный по HTTPS, чтобы вебхук мог дойти

## Установка

1. Скопируйте содержимое архива в Paymenter так, чтобы файлы оказались здесь:

   ```
   extensions/Gateways/SnapexPay/SnapexPay.php
   extensions/Gateways/SnapexPay/routes.php
   ```

   Папка должна называться ровно `SnapexPay`, как класс. Например:

   ```bash
   cd /var/www/paymenter
   curl -fsSLO https://pay.snapex.pro/downloads/paymenter/snapexpay-paymenter-1.1.0.zip && unzip snapexpay-paymenter-1.1.0.zip
   mv snapexpay-paymenter-1.1.0 extensions/Gateways/SnapexPay
   chown -R www-data:www-data extensions/Gateways/SnapexPay   # пользователь веб-сервера
   ```

   Если Paymenter запущен в Docker, положите папку в `/app/extensions/Gateways/SnapexPay` (том `./extensions`).

2. Если шлюз не появился в списке, выполните `composer dump-autoload -o` и `php artisan optimize:clear`.
3. В Paymenter откройте **Admin → Gateways → New**, выберите **SnapEX Pay** и заполните настройки.

## Настройки

| Поле (ключ) | По умолчанию | Описание |
|---|---|---|
| API key (`api_key`) | — | Обязательно. Ключ из кабинета SnapEX Pay → API keys со скоупами `invoices.read` и `invoices.write`. |
| Webhook secret (`webhook_secret`) | — | Обязательно. Секрет `whsec_…`, который показывается при создании вебхука. Вебхуки без подписи или с неверной подписью отклоняются. |
| Network fee paid by (`fee_on`) | `merchant` | `merchant` — клиент платит сумму счёта, `customer` — сетевая комиссия добавляется сверху. |
| Payment window, minutes (`expires_minutes`) | `60` | 1–1440. Сколько минут счёт SnapEX доступен для оплаты. |
| USD per 1 invoice currency (`usd_rate`) | `1` | Оставьте `1`, если счета в USD. Для EUR по курсу 1.08 укажите `1.08`. Счета SnapEX всегда в USD. |
| Environment (`environment`) | `live` | `live` или `test`. Тестовые счета подтверждаются без реальных денег. |
| Partner ID (`partner_id`) | пусто | Необязательно. Partner ID интегратора, который настроил модуль (реферальная программа SnapEX Pay). Оставьте пустым, если ставите сами. |
| API URL (`api_url`) | `https://snapex.pro/api/v1/merchant` | Менять не нужно. |

## Настройка вебхука

В кабинете SnapEX Pay добавьте вебхук:

```
https://ваш-paymenter.example/extensions/snapexpay/webhook
```

Подпишите его на события `invoice.*` (как минимум `invoice.paid`) и вставьте секрет `whsec_…` в настройки шлюза. Этот маршрут принимает POST-запросы от сервера к серверу, поэтому CSRF и сессии он не использует: запрос подтверждается подписью.

## Проверка

- **Без денег:** переключите *Environment* на `test`, оплатите счёт и подтвердите тестовый платёж на странице SnapEX Pay. Счёт должен перейти в статус *Paid*, с одной транзакцией `INV-…`.
- **Автотесты:** `php tests/run.php` (PHP 8.3, без зависимостей). Они проверяют контракт расширения Paymenter, подпись и окно повтора, формат запроса к API, идемпотентность вебхука, разделение test/live и изоляцию сайтов на мок-API SnapEX.

## Вопросы и проблемы

**Шлюза нет в Admin → Gateways.**
Проверьте путь `extensions/Gateways/SnapexPay/SnapexPay.php` (регистр букв важен) и права на чтение для веб-сервера. Затем выполните `composer dump-autoload -o && php artisan optimize:clear`.

**Клиент оплатил, а счёт не закрылся.**
Посмотрите доставку вебхуков в кабинете SnapEX Pay. Ответ `400 invalid signature` — секрет не совпадает или часы сервера отстают больше чем на 5 минут. `not_paid` — SnapEX ещё не подтвердил платёж, вебхук придёт снова после подтверждения. `ignored_test_payment` — оплачен тестовый счёт, а шлюз в режиме `live`.

**Клиент заплатил меньше, чем указано в счёте.**
Счета в статусе `partially_paid` автоматически не зачисляются. Проверьте платёж в кабинете SnapEX и добавьте транзакцию в Paymenter вручную.

**«Amount is below the … USD minimum».**
SnapEX Pay ограничивает минимальную сумму счёта. Это сообщение приходит напрямую от API.

**Я сменил `APP_URL`.**
Счета каждого сайта помечаются хешем `APP_URL`. Счета, созданные до смены, автоматически не закроются: внесите такие платежи вручную.

**Нужен ли свой кошелёк или нода?**
Нет. Блокчейн отслеживает SnapEX Pay, а деньги поступают на ваш мерчант-баланс.

## Другие интеграции SnapEX Pay

Все модули, тарифы и регистрация: **[pay.snapex.pro](https://pay.snapex.pro)**.

- [Marzban](https://pay.snapex.pro/integrations/marzban)
- [3x-ui](https://pay.snapex.pro/integrations/3x-ui)
- [Xboard / V2Board](https://pay.snapex.pro/integrations/xboard)
- [Remnawave](https://pay.snapex.pro/integrations/remnawave)
- [Telegram VPN bot](https://pay.snapex.pro/integrations/telegram-vpn-bot)
- [FOSSBilling](https://pay.snapex.pro/integrations/fossbilling)
- [n8n](https://pay.snapex.pro/integrations/n8n)

## Поддержка

- Сайт, кабинет и документация API: https://snapex.pro
- Ошибки и предложения — пишите в чат на [pay.snapex.pro](https://pay.snapex.pro).

## Лицензия

[MIT](LICENSE)
