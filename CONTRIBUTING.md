# Contributing to b10cks

First off — thank you. Whether you're fixing a typo, squashing a bug, or shipping a whole new feature, every contribution makes b10cks better for everyone. We're genuinely glad you're here.

This guide will get you from zero to merged PR as smoothly as possible.

---

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Ways to Contribute](#ways-to-contribute)
- [Getting Started](#getting-started)
- [Commit Messages — Gitmoji Required](#commit-messages--gitmoji-required)
- [AI-Assisted Contributions](#ai-assisted-contributions)
- [Pull Request Process](#pull-request-process)
- [Code Style](#code-style)
- [Reporting Bugs](#reporting-bugs)
- [Suggesting Features](#suggesting-features)

---

## Code of Conduct

We expect everyone in this community to be kind, patient, and constructive. Harassment, gatekeeping, and dismissiveness have no place here. Treat people the way you'd want to be treated on a bad day. The full text lives in [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

Violations can be reported to [hello@b10cks.com](mailto:hello@b10cks.com).

---

## Ways to Contribute

You don't have to write code to make a difference:

- **Report a bug** — even a well-written issue saves someone hours of guesswork
- **Improve docs** — spotted something confusing or missing? Fix it
- **Answer questions** — help others in [GitHub Discussions](https://github.com/b10cks/cms/discussions) or [Discord](https://discord.gg/mdcDktFFcp)
- **Review pull requests** — feedback from fresh eyes is invaluable
- **Translate** — help make b10cks accessible to more people
- **Write code** — bug fixes, features, performance improvements, tests

---

## Getting Started

### 1. Fork & clone

```bash
git clone https://github.com/<your-username>/cms.git
cd cms
```

### 2. Install dependencies

```bash
bun install
composer install
```

### 3. Set up your environment

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

You need PHP 8.5+, Composer 2.5+, Bun 1.0+, and MySQL 8.0+ (or MariaDB).

### 4. Run the app

Three processes need to run while developing:

```bash
php artisan serve         # Laravel backend
bun run dev               # Vite dev server for the admin UI
php artisan reverb:start  # WebSockets for real-time features
```

Optional: Redis 6.0+ for caching and queues, OpenSearch for relevance-tuned search.

### 5. Create a branch

Use a short, descriptive name:

```bash
git checkout -b fix/canvas-zoom-on-trackpad
git checkout -b feat/opensearch-wildcard-queries
```

Prefixes: `feat/`, `fix/`, `docs/`, `refactor/`, `test/`, `chore/`

### 6. Make your changes, then open a PR

---

## Commit Messages — Gitmoji Required

We use **[Gitmoji](https://gitmoji.dev/)** for all commit messages. This keeps the git log scannable, expressive, and a little bit fun.

### Format

```
<emoji> <short description in present tense>

[optional body]

[optional footer: closes #issue, co-authors, etc.]
```

### Rules

- Start every commit with exactly one gitmoji
- Use the **imperative, present tense** — "Add feature" not "Added feature"
- Keep the subject line under **72 characters**
- Separate subject from body with a blank line

### Common emojis

| Emoji | Use for                  |
| ----- | ------------------------ |
| ✨    | New feature              |
| 🐛    | Bug fix                  |
| 📝    | Documentation            |
| ♻️    | Refactoring              |
| 🎨    | Code style / structure   |
| ⚡️    | Performance improvement  |
| 🔒    | Security fix             |
| 🧪    | Adding tests             |
| 🏗️    | Architectural change     |
| 🌐    | Internationalization     |
| 🔧    | Config / tooling         |
| 🚀    | Deployment / infra       |
| 🩹    | Minor / non-critical fix |
| 🗑️    | Removing code or files   |
| 🔥    | Removing dead code       |
| 💄    | UI / style changes       |
| 🚸    | UX improvement           |
| 💬    | Text / copy changes      |

Full reference: [gitmoji.dev](https://gitmoji.dev/)

### Examples

```
✨ Add OpenSearch wildcard query support

Extends the search driver interface with optional wildcard matching.
Falls back gracefully when the driver does not support it.

Closes #312
```

```
🐛 Fix canvas zoom resetting on trackpad pinch-out
```

```
📝 Document AI disclosure requirements in CONTRIBUTING
```

Commits that do not follow this format will be asked to revise before merging.

---

## AI-Assisted Contributions

We welcome AI-assisted contributions. Using Copilot, Claude, GPT, Cursor, or similar tools to help write code, tests, or docs is totally fine — just be transparent about it.

### What we ask

**1. Disclose AI usage in your PR description.**

If any part of your contribution was meaningfully shaped by an AI tool, say so. A single line is enough:

```
> AI assistance: Used Claude to draft the initial OpenSearch driver implementation, then reviewed and adjusted manually.
```

You don't need to disclose autocomplete suggestions or minor one-liners. The spirit of this rule is: if you'd credit a human pair-programmer, credit the AI.

**2. Understand what you're submitting.**

You are responsible for the code in your PR — not the model that helped generate it. Before opening a PR, make sure you can explain what the code does, why it's correct, and how it was tested. If you can't, keep iterating.

**3. Do not submit AI output verbatim without review.**

AI-generated code often looks plausible but contains subtle bugs, security issues, or violations of project conventions. Review everything. Run the tests. Read the diff.

**4. No hallucinated dependencies.**

Double-check that every package, function, or API reference in AI-generated code actually exists at the version we use. Phantom imports are a common failure mode.

### Why we have this policy

AI tools are genuinely useful and we don't want to pretend otherwise. But transparency builds trust — with maintainers, with reviewers, and with the community that relies on this codebase. A brief disclosure costs you nothing and tells everyone that you took the time to review what you're shipping.

---

## Pull Request Process

1. **Open a draft PR early** if you want early feedback — you don't need to finish before asking questions
2. **Fill in the PR template** — describe what changed and why, not just what the diff shows
3. **Link related issues** with `Closes #123` or `Relates to #456`
4. **Keep PRs focused** — one logical change per PR is easier to review and easier to revert if something goes wrong
5. **Respond to review comments** — maintainers put time into feedback; engage with it
6. **Squash noise commits** before merge — "fix typo", "oops", "try again" commits should be folded in

A PR is ready to merge when:

- All CI checks pass
- At least one maintainer has approved
- Conversations are resolved

---

## Code Style

### PHP (Laravel)

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) — enforced by Laravel Pint
- Run `./vendor/bin/pint` before pushing

### TypeScript / Vue

- oxlint + oxfmt — run `bun run lint` and `bun run format` before pushing
- Vue components use the Composition API with `<script setup>`
- Composables follow the `use*.ts` naming convention and live in `resources/js/composables/`

### General

- Write code for the next person, not just the computer
- Leave things cleaner than you found them
- Avoid over-engineering — simple and correct beats clever and fragile

---

## Reporting Bugs

A good bug report lets someone reproduce the problem without having to guess. Please include:

- **b10cks version** (or commit SHA)
- **Environment**: PHP version, Node version, database, OS
- **Steps to reproduce** — be specific
- **Expected behavior**
- **Actual behavior**
- **Logs or screenshots** if relevant

Open a bug report in [GitHub Issues](https://github.com/b10cks/cms/issues/new/choose).

---

## Suggesting Features

Feature requests are welcome. Before opening one, check [existing discussions](https://github.com/b10cks/cms/discussions) and issues to avoid duplicates.

A good feature request explains:

- The problem you're trying to solve (not just the solution you have in mind)
- Who benefits and how
- Whether you'd be willing to help implement it

Start a conversation in [GitHub Discussions](https://github.com/b10cks/cms/discussions) — features that gain community traction move up the priority list.

---

## Questions?

If you're unsure about anything — scope, approach, conventions — just ask before you invest time writing code. Open a discussion, drop by [Discord](https://discord.gg/mdcDktFFcp), or leave a comment on the relevant issue.

We'd rather answer a question upfront than ask you to rewrite a PR.

---

<div align="center">
  <p>Built with 🖤 by the b10cks community</p>
</div>
