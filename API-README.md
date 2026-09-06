# OpenFunnels REST API

Token-based API for programmatic access to funnels, templates, and AI generation.

## Authentication

All API routes (except token generation) require a Bearer token:

```
Authorization: Bearer <your-api-token>
```

### Generate Token

First, log in via the web UI (http://localhost:8000), then:

```bash
curl -X POST http://localhost:8000/api/token \
  -H "Authorization: Bearer <session-cookie>" \
  -H "Accept: application/json"
```

Response:
```json
{
  "token": "abc123...",
  "message": "Token generated. Store it securely — it won't be shown again."
}
```

### Revoke Token

```bash
curl -X DELETE http://localhost:8000/api/token \
  -H "Authorization: Bearer <your-api-token>"
```

## Endpoints

### Funnels

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/funnels` | List all funnels |
| POST | `/api/funnels` | Create a new funnel |
| GET | `/api/funnels/{id}` | Get funnel with full content |
| PUT | `/api/funnels/{id}` | Update funnel |
| DELETE | `/api/funnels/{id}` | Delete funnel |
| POST | `/api/funnels/{id}/publish` | Publish funnel |
| POST | `/api/funnels/{id}/unpublish` | Unpublish funnel |
| POST | `/api/funnels/{id}/duplicate` | Duplicate funnel |
| POST | `/api/funnels/generate` | AI-generate a funnel |

### Templates

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/funnels/{id}/template` | Export as .openfunnels.json |
| POST | `/api/templates/import` | Import template → new funnel |

## Examples

### Create Funnel

```bash
curl -X POST http://localhost:8000/api/funnels \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Landing Page",
    "description": "Crypto consultation landing",
    "content": {
      "sections": [
        {
          "type": "section",
          "layout": "single",
          "columns": [
            {
              "type": "column",
              "width": 100,
              "blocks": [
                {
                  "type": "text",
                  "content": {
                    "text": "<h1>Welcome to Our Service</h1><p>We help you succeed.</p>"
                  }
                }
              ]
            }
          ],
          "settings": {
            "backgroundColor": "#1a1a2e",
            "padding": "80px 24px",
            "minHeight": "60vh",
            "fullWidth": true
          }
        }
      ]
    }
  }'
```

### Update Funnel Content

```bash
curl -X PUT http://localhost:8000/api/funnels/1 \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "content": {
      "sections": [...]
    }
  }'
```

### Publish Funnel

```bash
curl -X POST http://localhost:8000/api/funnels/1/publish \
  -H "Authorization: Bearer <token>"
```

### Import Template

```bash
curl -X POST http://localhost:8000/api/templates/import \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "template": {
      "kind": "openfunnels-template",
      "schemaVersion": 1,
      "metadata": {
        "name": "Crypto Landing Page",
        "category": "landing",
        "tags": ["crypto", "fintech"]
      },
      "funnel": {
        "content": { "sections": [...] },
        "settings": { "backgroundColor": "#ffffff", "maxWidth": "1200px" }
      }
    }
  }'
```

## Funnel Content Schema

```
Funnel
  └─ content: { sections: Section[] }
       └─ Section
            ├─ id: string (auto-generated)
            ├─ type: "section"
            ├─ layout: "single" | "two-column" | "three-column" | "four-column"
            ├─ columns: Column[]
            └─ settings: { backgroundColor, padding, margin, minHeight, fullWidth }

Column
  ├─ id: string (auto-generated)
  ├─ type: "column"
  ├─ width: number (percentage, e.g. 50 for two-column)
  ├─ blocks: Block[]
  └─ settings: { padding, backgroundColor, verticalAlign }

Block
  ├─ id: string (auto-generated)
  ├─ type: text | image | button | form | video | code | map | testimonial | calendar | ecommerce | team | chart | audio | countdown | social | spacer | container | grid | tabs | accordion
  ├─ content: { ... } (type-specific)
  └─ settings: { padding, margin, backgroundColor, borderRadius }
```
