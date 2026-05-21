# yii3-spawn — план интеграции Yii3 с TrueAsync server

Документ описывает, как адаптировать фреймворк **Yii3** для работы под
[TrueAsync server](https://github.com/true-async/server). За основу взяты уже
существующие адаптеры `symfony-spawn` и `laravel-spawn` — у них одинаковый
архитектурный каркас, который переносится на Yii3.

---

## 1. Цель

Запустить обычное Yii3-приложение на TrueAsync-сервере так, чтобы:

- приложение собиралось **один раз на воркер** (DI-контейнер, маршруты, конфиг);
- внутри воркера запросы обрабатывались **конкурентно**, каждый в своей корутине;
- **код приложения не менялся** — контроллеры, middleware, ActiveRecord, шаблоны
  работают как обычно;
- состояние, привязанное к запросу, было изолировано между корутинами.

---

## 2. Как работает TrueAsync server и что показывают существующие адаптеры

TrueAsync server (`TrueAsync\HttpServer`) — это многопоточный HTTP-сервер на корутинах:

- `spawn_thread()` поднимает N воркеров (обычно по числа ядер);
- в каждом воркере `HttpServer` принимает соединения и на каждый запрос спавнит
  корутину;
- корутины внутри одного воркера разделяют память процесса — то есть **все
  синглтоны и статика общие**;
- `Async\request_context()` даёт хранилище, изолированное по корутине/скоупу — это
  ключевой примитив изоляции.

`symfony-spawn` и `laravel-spawn` решают одну и ту же задачу одинаково:

| Слой | Назначение |
|---|---|
| **Runtime / Runner** | точка входа; поднимает воркеры через `spawn_thread`, в каждом строит ядро фреймворка и запускает `HttpServer` |
| **Server adapter** | конвертирует `TrueAsync\HttpRequest` ↔ объект запроса фреймворка и обратно для ответа |
| **Per-coroutine адаптеры** | оборачивают stateful-синглтоны, перенося их изменяемое состояние в `request_context()` |
| **DB pool + транзакции** | включают C-уровневый пул PDO TrueAsync и изолируют вложенность транзакций по корутине |
| **Memory hygiene** | сбрасывают накопленное за запрос состояние (identity map и пр.) |

Главная мысль: **разделяемое read-only состояние остаётся общим** (конфиг,
определения маршрутов, кэш переводов), **изменяемое per-request состояние —
изолируется**.

---

## 3. Архитектура Yii3 и где она ломается под concurrency

Yii3 устроен заметно удобнее Yii2 и Symfony для async:

- **нет глобального `Yii::$app`** — никакого God-синглтона;
- запрос (`Psr\Http\Message\ServerRequestInterface`) — это **локальная
  переменная**, протекающая через PSR-15 middleware-стек, а не синглтон. Это
  снимает самую больную проблему Yii2/Symfony;
- DI-контейнер `Yiisoft\Di\Container` строится из групп конфигов
  (`di-web`, `di-providers-web`, ...);
- HTTP-приложение — `Yiisoft\Yii\Http\Application`: `start()`, далее
  `handle(ServerRequestInterface): ResponseInterface` через `MiddlewareDispatcher`,
  затем `afterEmit()` / `shutdown()`;
- раннеры (`Yiisoft\Yii\Runner\ApplicationRunner`, реализации `RunnerInterface`)
  уже абстрагируют точку входа — есть HTTP-раннер (`HttpApplicationRunner`) и
  RoadRunner-раннер;
- **уже существует `Yiisoft\Di\StateResetter`** — между запросами RoadRunner-раннер
  вызывает `StateResetter::reset()`, сбрасывая stateful-сервисы.

### В чём проблема

`StateResetter` рассчитан на **последовательное** переиспользование воркера
(RoadRunner: один запрос за раз). Под TrueAsync N корутин работают **одновременно**
в одном воркере — «сброс между запросами» здесь некорректен: запросы перекрываются
во времени. Поэтому stateful-синглтоны, которые в RoadRunner-режиме просто
сбрасываются, под async нужно **изолировать по корутине**.

### Stateful-синглтоны Yii3, требующие изоляции

| Синглтон | Что хранит | Когда мутируется |
|---|---|---|
| `Yiisoft\Router\CurrentRoute` | сматченный маршрут, аргументы, URI | router middleware |
| `Yiisoft\User\CurrentUser` | аутентифицированная identity, кэш проверок прав | auth middleware, код приложения |
| `Yiisoft\Session\SessionInterface` | данные сессии, флеш-сообщения | весь жизненный цикл запроса |
| `Yiisoft\RequestProvider\RequestProvider` | текущий `ServerRequest` для не-middleware кода | `RequestCatcherMiddleware` |
| View state (`Yiisoft\View\View` / `ViewRenderer`) | общие параметры шаблонов, блоки, заголовок | контроллеры, layout |
| Error handler | сохранённый текущий запрос для отрисовки ошибки | при исключении |
| `Yiisoft\Db\Connection\ConnectionInterface` | объект активной транзакции, уровень savepoint | `transaction()` / `beginTransaction()` |

Всё остальное (определения контейнера, маршруты, конфиг, translation-loader,
DBAL-схема) — read-only после сборки и безопасно для общего доступа.

---

## 4. Стратегия адаптации

**Выбрана: общий контейнер + изоляция состояния через `request_context()`**
(тот же подход, что в `symfony-spawn` / `laravel-spawn`).

- контейнер строится один раз на воркер;
- каждая корутина-запрос работает в собственном дочернем `Scope`, поэтому
  `request_context()` уникален для запроса;
- проблемные синглтоны заменяются на async-варианты через **DI-делегаты/декораторы**
  в отдельной группе конфигов (`di-web` override), которую подключает наш раннер;
- замена прозрачна: классы реализуют те же интерфейсы Yii3, что и оригиналы.

Альтернатива — отдельный дочерний контейнер на корутину — отклонена: `Yiisoft\Di\Container`
не предназначен для дешёвого порождения child-контейнеров, и пересборка
request-scoped сервисов на каждый запрос дороже точечной изоляции.

---

## 5. Компоненты-адаптеры

| Компонент | Адаптер (`TrueAsync\Yii3\…`) | Что изолируется |
|---|---|---|
| Runner | `Runtime\TrueAsyncRunner` | точка входа: воркеры + `HttpServer` |
| Server | `Server\TrueAsyncServer` | `HttpRequest` ↔ PSR-7 ↔ `HttpResponse` |
| PSR-7 мост | `Server\PsrRequestFactory` / `PsrResponseEmitter` | конвертация запроса/ответа |
| Текущий маршрут | `Router\AsyncCurrentRoute` | сматченный маршрут на корутину |
| Аутентификация | `User\AsyncCurrentUser` | identity и кэш прав на корутину |
| Сессия | `Session\AsyncSession` | данные сессии и флеш на корутину |
| Request provider | `Http\AsyncRequestProvider` | текущий `ServerRequest` на корутину |
| View | `View\AsyncViewState` | общие параметры/блоки шаблона на корутину |
| БД: драйвер | `Db\TrueAsyncPgsqlDriver` | PDO с пулом TrueAsync (пароль в DSN, errmode arg3) |
| БД: транзакции | `Db\AsyncTransactionState` | уровень вложенности/savepoint на корутину |
| DI | `Di\AsyncConfigGroup` (config-набор) | подмена синглтонов на async-версии |
| Гигиена памяти | `Runtime\RequestCleanup` | сброс identity map / state после запроса |

### Безопасно без адаптации

Контроллеры, middleware, hydrator/`request-model`, валидация, кэш-бэкенды,
HTTP-клиент, логгер, mailer, translation-каталоги — либо создаются per-request,
либо stateless.

---

## 6. Этапы реализации

Каждый этап — самостоятельный, с явным критерием готовности.

### Этап 0 — каркас репозитория ✅
`composer.json`, `README.md`, `.gitignore`, `PLAN.md`, структура `src/`.
**Готово:** репозиторий клонируется, `composer install` проходит.

### Этап 1 — Runner + Server, single-worker
`Runtime\TrueAsyncRunner` (реализует `Yiisoft\Yii\Runner\RunnerInterface`),
`Server\TrueAsyncServer` на `TrueAsync\HttpServer`, мост PSR-7
(`PsrRequestFactory` / `PsrResponseEmitter`). Контейнер строится через базовый
`ApplicationRunner`; запрос гоняется через `Yiisoft\Yii\Http\Application::handle()`.
**Готово:** дефолтное Yii3-приложение отвечает 200 на 1 воркере, без конкурентности.

### Этап 2 — многопоточный режим
Поднятие N воркеров через `spawn_thread` + bootloader (autoload в потоке),
проброс нужных env-переменных в поток (по образцу `symfony-spawn`).
**Готово:** N воркеров обслуживают запросы, дефолтный роут стабилен под `h2load`.

### Этап 3 — изоляция per-coroutine состояния
`AsyncCurrentRoute`, `AsyncRequestProvider`, `AsyncViewState`, `AsyncSession`.
Подмена через config-группу `di-web`, подключаемую раннером.
**Готово:** конкурентный тест (две корутины с разными маршрутами/сессиями) не
видит чужого состояния.

### Этап 4 — аутентификация
`AsyncCurrentUser` — identity и кэш проверок прав в `request_context()`.
**Готово:** конкурентные запросы с разными identity изолированы; флеш-сообщения
не утекают между корутинами.

### Этап 5 — база данных
`TrueAsyncPgsqlDriver` (PDO-пул TrueAsync; пароль в DSN, errmode третьим
аргументом — как в `symfony-spawn`), `AsyncTransactionState` для вложенности
транзакций по корутине, прогрев пула в первой корутине.
**Готово:** ActiveRecord/`yiisoft/db` работает; конкурентные транзакции не мешают
друг другу; виден прирост RPS на пуле (см. `feedback_pdo_pool_stmt_cache`).

### Этап 6 — гигиена памяти и устойчивость
Сброс per-request состояния и identity map после запроса; откат «забытых»
транзакций перед возвратом соединения в пул; firewall на OOM/исключения, чтобы
воркер не падал в SEGV.
**Готово:** длительная нагрузка без роста RSS и без падений воркеров.

### Этап 7 — статика, TLS, HTTP/2
Проброс `StaticHandler`, TLS-листенеров и протоколов через конфиг
(по образцу `TrueAsyncServer::buildConfig()` из `symfony-spawn`).
**Готово:** статика, TLS и h2 работают; конфиг покрыт настройками.

### Этап 8 — тесты, бенчмарк, документация
PHPUnit на адаптеры, конкурентные интеграционные тесты, прогон в HttpArena,
`ADAPTATION.md` (что адаптировано / что небезопасно / как писать async-safe код —
по образцу `laravel-spawn`).
**Готово:** CI зелёный, бенчмарк-цифры RPS зафиксированы.

---

## 7. Структура репозитория

```
yii3-spawn/
├── composer.json
├── README.md
├── PLAN.md                  # этот документ
├── ADAPTATION.md            # появится на этапе 8
├── config/                  # config-группы Yii3 с подменами синглтонов
│   └── di-web.php
├── src/
│   ├── Runtime/
│   │   ├── TrueAsyncRunner.php
│   │   └── RequestCleanup.php
│   ├── Server/
│   │   ├── TrueAsyncServer.php
│   │   ├── PsrRequestFactory.php
│   │   └── PsrResponseEmitter.php
│   ├── Router/AsyncCurrentRoute.php
│   ├── User/AsyncCurrentUser.php
│   ├── Session/AsyncSession.php
│   ├── Http/AsyncRequestProvider.php
│   ├── View/AsyncViewState.php
│   ├── Db/
│   │   ├── TrueAsyncPgsqlDriver.php
│   │   └── AsyncTransactionState.php
│   └── ScopedKey.php         # enum-ключи для request_context()
└── tests/
```

---

## 8. Открытые вопросы и риски

1. **Точные сигнатуры классов Yii3.** Версии `yiisoft/*` (особенно `router`,
   `user`, `db`) различаются мажорами — на этапе 1 нужно зафиксировать версии в
   `composer.json` по реальному `yiisoft/app` и сверить интерфейсы.
2. **`final`-классы.** Если `CurrentRoute` / `Session` объявлены `final`, замена
   через наследование невозможна — тогда декоратор + DI-делегат (как
   `AsyncTranslator` в `symfony-spawn`).
3. **`StateResetter`.** Нужно убедиться, что его сброс не вызывается на общих
   синглтонах в async-режиме — иначе он затрёт состояние чужой корутины. Вероятно
   `StateResetter::reset()` в async-раннере не вызываем вовсе.
4. **Внутренняя статика пакетов Yii3.** Точечно проверить статические свойства в
   `yiisoft/*` (логирование, профайлинг, error handler) — по образцу
   PHPStan-правила `MutableStaticPropertyRule` из `laravel-spawn`.
5. **Совместимость PDO-драйвера.** TrueAsync меняет сигнатуру конструктора `PDO`
   (пароль в DSN, errmode arg3) — `yiisoft/db-pgsql` `Driver` нужно обернуть, а не
   использовать как есть.
6. **Прогрев пула.** Пул PDO создаётся только когда работает планировщик корутин —
   прогревать в первой корутине, не на этапе boot (см. `symfony-spawn DevServer`).

---

## 9. Ссылки

- TrueAsync server — https://github.com/true-async/server
- PHP TrueAsync — https://github.com/true-async/php-async
- Образцы адаптеров — `~/symfony-spawn`, `~/laravel-spawn`
- Yii3 HTTP runner — `yiisoft/yii-runner-http`, `yiisoft/yii-runner-roadrunner`
