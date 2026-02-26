# Laravel Hostinger Deploy

[![Packagist](https://img.shields.io/packagist/v/ahlaw/laravel-hostinger-deploy)](https://packagist.org/packages/ahlaw/laravel-hostinger-deploy)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Deploy your Laravel application to Hostinger shared hosting with a **single command** — and automate all future deployments via GitHub Actions. No manual server configuration required.

---

## Features

- 🧙 **Interactive setup wizard** — no pre-configuration needed, just run the command
- 🚀 **One-command deploy + CI/CD setup** (`hostinger:deploy-and-setup-cicd`)
- 🔑 **Automatic SSH key generation** on the server, per domain
- 🔗 **Auto-adds deploy key to GitHub** via API
- ⚙️ **Creates all GitHub Actions secrets** automatically
- 📄 **Publishes workflow file** to your repo (`.github/workflows/hostinger-deploy.yml`)
- 🌐 **Multi-domain support** — unique SSH keys per domain
- ✅ **Works on Windows** — no `sshpass` required, uses `phpseclib`

---

## Requirements

- PHP 8.2+
- Laravel 10+
- A Hostinger shared hosting plan with SSH access
- A GitHub repository for your project
- A GitHub Personal Access Token with `repo` scope

---

## Installation

```bash
composer require ahlaw/laravel-hostinger-deploy --dev
```

---

## Quick Start

Just run this single command from your Laravel project root:

```bash
php artisan hostinger:deploy-and-setup-cicd
```

If your `.env` doesn't have the required variables yet, the **interactive wizard** will ask you for them and save them automatically:

```
🔧 Let's configure your Hostinger deployment settings:
  Hostinger SSH Host: srv123456.main-hosting.eu
  Hostinger SSH Username: u123456789
  Hostinger SSH Password: ••••••••
  Website folder (e.g. pikxx.com): mysite.com
  GitHub API Token: ••••••••••••••••••••••
  💾 Saving your configuration to .env file...
```

### What this command does

1. ✅ Connects to your Hostinger server via SSH
2. ✅ Generates a unique SSH deploy key on the server (`~/.ssh/id_rsa_<sitename>`)
3. ✅ Clones your GitHub repository to `~/domains/<your-site>/`
4. ✅ Creates the `public_html → public/` symlink so Hostinger serves Laravel correctly
5. ✅ Copies `.env.example` → `.env` and generates an `APP_KEY`
6. ✅ Runs `composer install`, `migrate`, `optimize`, etc.
7. ✅ Adds the deploy key to your GitHub repository via API
8. ✅ Creates all required **GitHub Actions secrets** (`SSH_HOST`, `SSH_USERNAME`, `SSH_PORT`, `SSH_KEY`, `WEBSITE_FOLDER`)
9. ✅ Publishes `.github/workflows/hostinger-deploy.yml` to your project

---

## Environment Variables

The wizard will prompt for missing variables. You can also set them manually in your `.env`:

```env
HOSTINGER_SSH_HOST=srv123456.main-hosting.eu
HOSTINGER_SSH_USERNAME=u123456789
HOSTINGER_SSH_PASSWORD=your-password
HOSTINGER_SITE_DIR=mysite.com
HOSTINGER_SSH_PORT=65002
GITHUB_API_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxx
```

> **Security:** Your password is never stored in Git. It is only written to your local `.env` file.

---

## GitHub Personal Access Token

Generate a token at [GitHub → Settings → Developer Settings → Personal Access Tokens → Classic](https://github.com/settings/tokens/new) with these scopes:

- ✅ `repo` (Full control of private repositories)
- ✅ `admin:repo_hook`

---

## Individual Commands

### Deploy only

```bash
php artisan hostinger:deploy
```

Options:
- `--fresh` — Wipe the server folder and do a fresh clone
- `--site-dir=mysite.com` — Override the site directory
- `--show-errors` — Show detailed SSH error output

### Setup CI/CD only (after deploying)

```bash
php artisan hostinger:setup-cicd
```

This creates GitHub secrets and publishes the workflow file.

---

## GitHub Actions Workflow

After running the setup command, commit and push the published workflow:

```bash
git add .github/workflows/hostinger-deploy.yml
git commit -m "Add Hostinger deployment workflow"
git push
```

Every push to your default branch will now automatically:

1. Pull latest code from GitHub on the Hostinger server
2. Run `composer install --ignore-platform-reqs`
3. Auto-create `.env` from `.env.example` if missing
4. Generate a valid `APP_KEY` using `openssl` (no PHP CLI version dependency)
5. Create `public_html → public/` symlink if missing
6. Create `storage:link` if missing
7. Run `php artisan migrate --force`
8. Run all optimization commands (`config:cache`, `route:cache`, `view:cache`, `optimize`)

---

## Multi-Domain Deployments

Each domain gets its own unique SSH key on the server. Run the command from each Laravel project:

```bash
# Project 1
cd ~/projects/mysite
php artisan hostinger:deploy-and-setup-cicd

# Project 2
cd ~/projects/anothersite
php artisan hostinger:deploy-and-setup-cicd
```

SSH keys are named after the site directory (e.g., `id_rsa_mysite_com`, `id_rsa_anothersite_com`) to avoid conflicts.

---

## Troubleshooting

### "SSH connection failed"
- Verify your `HOSTINGER_SSH_HOST` and `HOSTINGER_SSH_PORT` (Hostinger uses port `65002` by default, not `22`)
- Check that SSH access is enabled in your Hostinger hPanel

### "GitHub API connection failed"
- Ensure your `GITHUB_API_TOKEN` is a valid Classic token (not fine-grained) with `repo` scope

### "composer install" fails with PHP version error
- The workflow uses `--ignore-platform-reqs` to bypass this — the web server uses a different PHP version than the SSH CLI

### Website shows default Hostinger page
- The `public_html → public/` symlink may not exist — run `php artisan hostinger:deploy` again to recreate it

---

## License

MIT © [ahlaw](https://github.com/riyanshahlawat)
