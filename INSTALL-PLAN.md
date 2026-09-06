# OpenFunnels — План установки и запуска

**Дата:** 2026-09-06
**Статус:** ✅ Развёрнут локально

## Продукт

OpenFunnels — self-hosted open-source конструктор воронок + CRM-lite.
- **Стек:** Laravel 11 + React/TypeScript + Inertia + SQLite
- **Repo:** https://github.com/treder121p2p/openfunnels (форк aialvi/openfunnels)
- **Лицензия:** MIT

## Возможности

- Drag-and-drop редактор воронок (секции, колонки, блоки)
- 15 шаблонов, экспорт/импорт `.openfunnels.json`
- Формы захвата лидов + CRM (контакты, pipeline, сделки)
- Automation Studio (триггеры → действия, ветвления, вебхуки, email)
- A/B тесты, аналитика конверсий
- Публикация по slug-URL или custom domains
- AI-ассистент для генерации воронок (опционально)
- Демо-режим (sandbox для гостей)

## Установка

### 1. Клонирование
```
cd docker/
git clone https://github.com/treder121p2p/openfunnels.git
cd openfunnels
```

### 2. Исправление Dockerfile (DNS issue на Windows)
Добавлен `ENV NODE_OPTIONS=--dns-result-order=ipv4first` в секцию frontend —
иначе corepack/pnpm падают с ConnectTimeoutError из-за DNS в Docker на Windows.

### 3. Сборка и запуск
```
docker compose up --build -d
```

### 4. Проверка
```
docker ps --filter "name=openfunnels"
# web: Up (healthy) на порту 8000

curl http://localhost:8000/up
# 200 OK
```

## Сервисы

| Контейнер | Роль | Порт |
|---|---|---|
| openfunnels-web-1 | Основной app (Laravel) | 8000 (host) → 8000 (container) |
| openfunnels-queue-1 | Queue worker (Automation Studio) | — |
| openfunnels-scheduler-1 | Cron scheduler | — |

## Конфликт портов

| Сервис | Порт | Статус |
|---|---|---|
| qdrant | 6333-6334 | ✅ занят |
| searxng | 8888 | ✅ занят |
| erachain-api | 9048, 9061 | ⏹ Exited |
| erachain-gui | 6080, 9030, 9047, 9060 | ⏹ Exited |
| era-proxy | 8080 | ⏹ Exited |
| openscad-ai | 6081, 9880, 9881 | ⏹ Exited |
| belfort | 8090 | ⏹ Exited |
| freecad | 5900, 6080, 9875-9877 | ⏹ Exited |
| **openfunnels** | **8000** | ✅ **свободен, нет конфликтов** |

## Доступ

- **Локально:** http://localhost:8000
- **Демо-режим:** включён (Launch Demo)
- **Регистрация:** можно создать аккаунт
- **Дефолтный dev-login:** test@example.com / password (если seed включён)

## Управление

```powershell
# Запуск
cd docker\openfunnels
docker compose up -d

# Остановка (с сохранением данных)
docker compose down

# Остановка (с удалением данных)
docker compose down --volumes

# Логи
docker compose logs -f

# Пересборка
docker compose up --build -d
```

## Данные

- **Volume:** `openfunnels_openfunnels-data` (SQLite数据库)
- **Хранится:** /data/database.sqlite внутри контейнера
- **Бэкап:** `docker volume inspect openfunnels_openfunnels-data`

## Публикация в интернет (план, отложен)

| Вариант | Подходит для | Статус |
|---|---|---|
| GitHub Pages | ❌ Не подходит (нужен PHP-сервер) | — |
| Cloudflare Tunnel | Демо/показ клиенту | ⏳ Требует установки cloudflared |
| VPS + домен | Продакшен | ⏳ Требует VPS |
| GitHub Codespaces | Временный демо | ⏳ Не постоянный |

## REST API (добавлено 06.09.2026)

**Коммиты:** `3d8b35d` (API), `1645b8c` (MCP)

### Эндпоинты

| Метод | Путь | Описание |
|-------|------|----------|
| POST | `/api/token` | Генерация API-токена (из web-сессии) |
| DELETE | `/api/token` | Отзыв токена |
| GET | `/api/funnels` | Список воронок |
| POST | `/api/funnels` | Создать воронку |
| GET | `/api/funnels/{id}` | Получить воронку (полный контент) |
| PUT | `/api/funnels/{id}` | Обновить воронку |
| DELETE | `/api/funnels/{id}` | Удалить воронку |
| POST | `/api/funnels/{id}/publish` | Опубликовать |
| POST | `/api/funnels/{id}/unpublish` | Снять с публикации |
| POST | `/api/funnels/{id}/duplicate` | Дублировать |
| POST | `/api/funnels/generate` | AI-генерация |
| GET | `/api/funnels/{id}/template` | Экспорт шаблона |
| POST | `/api/templates/import` | Импорт шаблона |

### Аутентификация

```
Authorization: Bearer <api-token>
```

### MCP сервер

- **Путь:** `docker/openfunnels/mcp/`
- **Запуск:** `OPENFUNNELS_TOKEN=<token> node mcp/index.js`
- **11 инструментов:** list_funnels, get_funnel, create_funnel, update_funnel, delete_funnel, publish_funnel, unpublish_funnel, duplicate_funnel, generate_funnel, export_template, import_template

## Известные проблемы

- **DNS в Docker на Windows:** corepack/pnpm падают с ConnectTimeoutError. Решение: `NODE_OPTIONS=--dns-result-order=ipv4first` в Dockerfile.
- **compose.yaml:** файл называется `compose.yaml` (не `docker-compose.yml`).
