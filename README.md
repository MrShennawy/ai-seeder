<p align="center">
  <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="300" alt="Laravel Logo">
</p>

<h1 align="center">🌱 AiSeeder</h1>

<p align="center">
  <strong>AI-powered database seeding for Laravel 12 — realistic, schema-aware, zero-hallucination.</strong>
</p>

<p align="center">
  <a href="https://www.php.net/releases/8.2/en.php"><img src="https://img.shields.io/badge/PHP-8.2%2B-8892BF?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-12%2B-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 12+"></a>
  <a href="https://laravel.com/docs/12.x/ai-sdk"><img src="https://img.shields.io/badge/Laravel%20AI%20SDK-native-6366F1?style=flat-square" alt="Laravel AI SDK"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-green?style=flat-square" alt="MIT License"></a>
</p>

<p align="center">
  Stop writing fake data by hand. Stop trusting the AI with your IDs.<br>
  <code>AiSeeder</code> reads your database schema, resolves every relationship, and lets the AI generate <em>only</em> the creative parts — while PHP handles everything that must be exact.
</p>

---

## ⚡ Why AiSeeder?

Traditional seeders require you to hand-craft factories for every table. Faker gives you random strings — not *contextually realistic* data. And if you ask a raw LLM to generate INSERT statements, it will hallucinate IDs, violate foreign keys, and break JSON columns.

**AiSeeder solves all of this:**

| Problem | AiSeeder's Solution |
|---|---|
| AI invents fake IDs / ULIDs | PHP generates all PKs & FKs — AI never sees them |
| Parent table is empty | Recursively seeds parent tables automatically |
| `VARCHAR(2)` gets `"English"` | Schema analyzer extracts max lengths & ENUMs |
| JSON columns get plain strings | Strict structured output + post-processing |
| `password` column gets `"abc123"` | Auto-detected & filled with `Hash::make()` |
| No visual feedback during generation | Beautiful CLI with spinners, progress bars & token tracking |

---

## 📦 Installation

```bash
composer require mrshennawy/ai-seeder
```

The service provider is **auto-discovered**. Publish the config to customize defaults:

```bash
php artisan vendor:publish --tag=ai-seeder-config
```

Make sure you have at least one AI provider configured in your `config/ai.php`:

```env
# Any provider supported by the Laravel 12 AI SDK
OPENAI_API_KEY=sk-...
# or ANTHROPIC_API_KEY=sk-ant-...
# or GEMINI_API_KEY=...
# or a local Ollama instance
```

---

## 🚀 Quick Start

```bash
# Seed 10 rows into the users table (default)
php artisan ai:seed users

# Seed 100 rows
php artisan ai:seed orders --count=100

# Fully interactive mode — pick a table, count, and language
php artisan ai:seed
```

That's it. AiSeeder will analyze your `users` table, detect that `id` is a ULID, `password` needs hashing, and `email` must be unique — then ask the AI to generate only the creative columns like `name`, `bio`, and `phone`.

---

## 🔍 Feature Deep Dive

### 1. Smart Schema Introspection

AiSeeder reads your database schema at runtime — not your migrations, not your models — the **actual database state**. It extracts:

- ✅ Column names & data types
- ✅ `VARCHAR(n)` max lengths — enforced as hard limits on the AI *and* truncated in PHP as a safety net
- ✅ `ENUM('active','inactive','pending')` — AI is constrained to only these values
- ✅ `UNIQUE` constraints — AI generates distinct values per row
- ✅ `NULLABLE` columns — AI is instructed to occasionally return `null`
- ✅ `JSON` / `JSONB` columns — AI returns structured objects/arrays, PHP `json_encode()`s them
- ✅ Password columns (`password`, `password_hash`, etc.) — auto-detected by name

```
 📋 Table: users — 10 row(s) to generate

 ┌──────────────────┬───────────┬─────────────────────────────┐
 │ Column           │ Type      │ Flags                       │
 ├──────────────────┼───────────┼─────────────────────────────┤
 │ id               │ char      │ PK (ULID), LEN(26)          │
 │ name             │ varchar   │ LEN(255)                    │
 │ email            │ varchar   │ UNIQUE, LEN(255)            │
 │ password         │ varchar   │ PASSWORD, LEN(255)          │
 │ language         │ varchar   │ LEN(2)                      │
 │ status           │ enum      │ ENUM(active|inactive)       │
 │ bio              │ text      │ NULL                        │
 │ preferences      │ json      │ NULL, JSON                  │
 │ created_at       │ timestamp │ NULL                        │
 │ updated_at       │ timestamp │ NULL                        │
 └──────────────────┴───────────┴─────────────────────────────┘
```

From this analysis, AiSeeder **excludes** `id`, `password`, `created_at`, and `updated_at` from the AI prompt entirely. The AI only generates: `name`, `email`, `language`, `status`, `bio`, and `preferences`.

---

### 2. Bulletproof IDs & Foreign Keys — Zero Hallucination

This is the architectural philosophy that makes AiSeeder production-safe:

> **The AI generates zero IDs. PHP handles 100% of structural integrity.**

| Column Type | Who Handles It | How |
|---|---|---|
| Auto-increment PK | Database | Excluded from AI prompt entirely |
| ULID primary key | PHP | `Str::ulid()` injected per row |
| UUID primary key | PHP | `Str::uuid()` injected per row |
| Foreign keys (`user_id`, etc.) | PHP | Random valid parent ID via `array_rand()` |
| `password` | PHP | `Hash::make('password')` injected |
| `created_at` / `updated_at` | PHP | `now()` injected |
| `deleted_at` | PHP | Set to `null` (soft-delete default) |

The AI never even *sees* these columns in the prompt. This means:

```
✅ 0% chance of SQLSTATE integrity constraint violations from hallucinated IDs
✅ 0% chance of invalid ULID format (like "A1" or "1234567890")
✅ 0% chance of FK pointing to a non-existent parent record
```

---

### 3. Auto-Resolving Relationships (Recursive Seeding)

When you seed a child table, AiSeeder checks every foreign key:

- **Parent has records?** → Fetches existing IDs automatically.
- **Parent is empty?** → Pauses, recursively seeds the parent table first, then resumes.

```bash
php artisan ai:seed cart_items --count=20 --lang=ar
```

```
 🔗 Resolving relationships...

 🔍 Resolving: user_id → users.id
 ⚠️  Parent table [users] is empty. Recursively seeding it first...

     ┌──────────┐
     │ AiSeeder │  ← Recursive child command for [users]
     └──────────┘
     🔍 Analyzing schema for table: [users]...
     🧠 Generating chunk 1/1 (5 rows)...
     ✅ Successfully seeded [users] with 5 rows.

 ✅ Fetched 5 ID(s) from [users].

 🔍 Resolving: cart_id → carts.id
 ✅ Parent table [carts] already has data. Fetched 12 existing IDs.

 🔍 Resolving: product_id → products.id
 ⚠️  Parent table [products] is empty. Recursively seeding it first...
 ...
```

The entire dependency tree resolves automatically. Language selection (`--lang`) propagates to all recursive child commands.

#### 🔄 Self-Referencing Tables

Tables like `categories` with a `parent_id → categories.id` are handled gracefully:

```bash
php artisan ai:seed categories --count=10
```

```
 🔍 Resolving: parent_id → categories.id
 🔄 Self-referencing FK [parent_id] on [categories] — table is empty, will use NULL.
```

AiSeeder detects the self-reference, **skips** recursive seeding (which would cause an infinite loop), and sets `parent_id = NULL` for the initial batch — creating root-level categories. If you run it again, subsequent rows will randomly pick from the existing category IDs.

---

### 4. Source Code Context Injection — The Power Feature

Database schemas tell you *what* a column is. But they can't tell you what a `content` JSON column should *look like* when `delivery_mode = 'online'` vs `'in_person'`.

**AiSeeder can read your actual PHP code:**

```bash
php artisan ai:seed course_items \
  --context="Modules\Course\Http\Requests\CourseItemRequest" \
  --count=20
```

```
 📄 Loading code context from: Modules\Course\Http\Requests\CourseItemRequest
   ✓ Loaded 87 lines of source code for AI context.
```

AiSeeder uses PHP's `ReflectionClass` to locate the source file, reads its raw content with `file_get_contents()`, and injects it directly into the AI prompt. For example, given a FormRequest like:

```php
class CourseItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|in:lesson,quiz,assignment',
            'content' => 'required|array',
            'content.location' => 'required_if:delivery_mode,in_person',
            'content.meeting_url' => 'required_if:delivery_mode,online',
            'content.duration_minutes' => 'required|integer|min:15',
        ];
    }
}
```

The AI will generate `content` as a proper JSON object:

```json
{
  "location": "Riyadh, Building 4, Room 201",
  "duration_minutes": 60
}
```

Instead of the broken flat array `["location", "Riyadh", "duration_minutes", 60]` it would normally produce.

**Use cases for `--context`:**

| Pass this class... | The AI learns about... |
|---|---|
| `StoreOrderRequest` | Conditional validation rules, required-with dependencies |
| `CourseItem` (Model) | `$casts`, morph maps, `$appends`, accessor logic |
| `PaymentObserver` | Business rules triggered on create |
| `UserPolicy` | Role/permission constraints for realistic role distribution |

> **💡 Tip:** This is especially powerful for e-learning platforms, ERP systems, or any domain where JSON columns carry complex, context-dependent structures — like Quran academy platforms with lesson content varying by `delivery_mode`.

---

### 5. Multi-Language Data Generation

Generate data in **any language** — or a realistic mix of multiple languages:

```bash
# All text in Arabic
php artisan ai:seed users --lang=ar

# Bilingual Arabic + English (simulates a real bilingual platform)
php artisan ai:seed courses --lang=ar,en

# Trilingual dataset
php artisan ai:seed products --lang=es,pt,fr

# Interactive selection (if --lang is omitted)
🌐 What language(s) should the generated data be in? (comma-separated for multiple, e.g., ar,en)
```

The language instruction is injected at the **system prompt level** of the AI agent, ensuring authentic names, titles, and descriptions:

```
# --lang=ar generates:
┌────────────────────────┬───────────────────────────────┐
│ name                   │ email                         │
├────────────────────────┼───────────────────────────────┤
│ محمود الشناوي            │ mahmoud.shennawy@example.com  │
│ فاطمة أحمد              │ fatima.ahmed@example.com      │
│ عبدالله خالد             │ abdullah.khaled@example.com   │
└────────────────────────┴───────────────────────────────┘

# --lang=ar,en generates a mix:
│ سارة محمد               │ sarah.m@example.com           │
│ John Mitchell           │ john.mitchell@example.com     │
│ أحمد يوسف               │ ahmed.youssef@example.com     │
```

> Technical values (emails, URLs, timestamps, IDs) always remain in ASCII/Latin characters.

---

### 6. Beautiful CLI UX & Token Tracking

Built entirely with [`laravel/prompts`](https://laravel.com/docs/12.x/prompts) for a modern, interactive experience:

```
 ┌ AiSeeder — Smart Database Seeder ────────────────────────────────┐
 │                                                                   │
 │  🔍 Analyzing schema for table: [orders]...                      │
 │  ⏳ Reading columns, indexes, and constraints...                  │
 │                                                                   │
 │  📋 Table: orders — 50 row(s) to generate                        │
 │  ┌────────────┬──────────┬───────────────────────┐               │
 │  │ Column     │ Type     │ Flags                 │               │
 │  ├────────────┼──────────┼───────────────────────┤               │
 │  │ id         │ char     │ PK (ULID), LEN(26)    │               │
 │  │ ...        │ ...      │ ...                   │               │
 │  └────────────┴──────────┴───────────────────────┘               │
 │                                                                   │
 │  🔗 Resolving relationships...                                   │
 │  🔍 Resolving: user_id → users.id                                │
 │    ✅ Fetched 25 ID(s) from [users].                              │
 │                                                                   │
 │  ⚙️  Plan: 50 rows in 1 chunk(s). Language: AR,EN.               │
 │  ◆ Proceed with seeding [orders]? (Yes)                          │
 │                                                                   │
 │  🧠 Generating chunk 1/1 (50 rows)...                            │
 │  ⏳ Waiting for AI to generate data (this may take a moment)...   │
 │    ✓ AI returned 50 row(s). Tokens: 1,847 prompt + 3,291 comp.  │
 │                                                                   │
 │  💾 Inserting chunk 1/1 into [orders] ████████████████████ 100%   │
 │                                                                   │
 │  ✅ Successfully seeded [orders] with 50 rows.                    │
 │                                                                   │
 │  📊 Token Usage Summary                                          │
 │  ┌─────────────────────┬────────┐                                │
 │  │ Metric              │ Tokens │                                │
 │  ├─────────────────────┼────────┤                                │
 │  │ Prompt tokens       │ 1,847  │                                │
 │  │ Completion tokens   │ 3,291  │                                │
 │  │ Total tokens        │ 5,138  │                                │
 │  └─────────────────────┴────────┘                                │
 └───────────────────────────────────────────────────────────────────┘
```

Token usage is aggregated across **all chunks and recursive parent seeding calls**, so you always know the full cost of a seeding operation.

---

## 📋 Command Reference

```bash
php artisan ai:seed [table] [options]
```

| Argument / Option | Description | Default |
|---|---|---|
| `table` | The database table to seed (interactive picker if omitted) | — |
| `--count=N` | Number of rows to generate | `10` |
| `--chunk=N` | Rows per AI request (smaller = safer for token limits) | `50` |
| `--lang=CODE` | Language(s) — single (`ar`) or comma-separated (`ar,en,fr`) | `en` |
| `--context=CLASS` | Fully-qualified PHP class for business logic context | — |
| `--no-interaction` | Skip all prompts (use defaults) | — |

### Examples

```bash
# Basic usage
php artisan ai:seed users --count=50

# Large dataset with small chunks to avoid token limits
php artisan ai:seed products --count=1000 --chunk=25

# Arabic-only data with FormRequest context
php artisan ai:seed course_items --count=30 --lang=ar \
  --context="Modules\Course\Http\Requests\CourseItemRequest"

# Fully non-interactive (CI/CD, scripts)
php artisan ai:seed users --count=5 --lang=en --no-interaction
```

---

## ⚙️ Configuration

```php
// config/ai-seeder.php

return [
    // Rows per AI request. Smaller = safer for tokens, larger = fewer API calls.
    'chunk_size' => env('AI_SEEDER_CHUNK_SIZE', 50),

    // Default row count when --count is not provided.
    'default_count' => env('AI_SEEDER_DEFAULT_COUNT', 10),

    // Retry attempts on AI failure (malformed JSON, wrong row count).
    'max_retries' => env('AI_SEEDER_MAX_RETRIES', 3),

    // Default language for generated text content.
    'default_language' => env('AI_SEEDER_DEFAULT_LANGUAGE', 'en'),
];
```

---

## 🏗️ Architecture

```
packages/shennawy/ai-seeder/
├── config/
│   └── ai-seeder.php                  # Published configuration
├── src/
│   ├── Agents/
│   │   └── SeederAgent.php            # Laravel AI SDK Agent (structured output)
│   ├── Console/Commands/
│   │   └── AiSeedCommand.php          # The ai:seed Artisan command
│   ├── Contracts/
│   │   ├── DataGeneratorInterface.php
│   │   ├── RelationshipResolverInterface.php
│   │   └── SchemaAnalyzerInterface.php
│   ├── AiSeederOrchestrator.php       # Programmatic API (non-CLI usage)
│   ├── AiSeederServiceProvider.php    # Auto-discovered service provider
│   ├── ContextExtractor.php           # ReflectionClass-based source reader
│   ├── DataGenerator.php              # Prompt builder + post-processor
│   ├── GenerationResult.php           # DTO: rows + token usage
│   ├── RelationshipResolver.php       # FK resolution + recursive seeding
│   ├── SchemaAnalyzer.php             # Database introspection engine
│   └── TokenUsageTracker.php          # Aggregates tokens across calls
└── tests/
    └── Feature/                       # 90+ Pest tests
```

All core services are bound via interfaces in the service provider, making them fully swappable and testable.

---

## 🌐 Cross-Provider Compatibility

AiSeeder works with **any provider** supported by the Laravel 12 AI SDK:

| Provider | Status | Notes |
|---|---|---|
| OpenAI (GPT-4o, etc.) | ✅ Fully supported | Best structured output compliance |
| Google Gemini | ✅ Fully supported | Schema sanitized for strict OpenAPI validation |
| Anthropic Claude | ✅ Fully supported | — |
| Ollama (local) | ✅ Fully supported | Great for development without API costs |

The structured output schema is carefully built to avoid provider-specific pitfalls:
- No `additionalProperties` key (Gemini rejects it)
- No array-type for nullable fields (Gemini rejects `["string", "null"]`)
- Nullability communicated via descriptions instead

---

## 🧪 Testing

The package ships with **90+ tests** covering schema analysis, post-processing, prompt building, relationship resolution, self-referencing FKs, and Gemini compatibility:

```bash
# Run from the main Laravel project
php artisan test packages/shennawy/ai-seeder/tests/

# Or with a filter
php artisan test --filter="self-referencing" packages/shennawy/ai-seeder/tests/
```

---

## 🛠️ Local Development

To use this package from a local path during development:

```json
// composer.json (main Laravel project)
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/shennawy/ai-seeder"
        }
    ]
}
```

```bash
composer require mrshennawy/ai-seeder:@dev
```

---

## 📄 License

AiSeeder is open-source software licensed under the [MIT License](LICENSE).

---

<p align="center">
  Built with ❤️ for the Laravel community by <a href="https://github.com/MrShennawy">Mahmoud Shennawy</a>
</p>
