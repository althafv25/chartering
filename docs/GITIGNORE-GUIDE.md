# .gitignore Configuration Guide

**Last Updated:** October 4, 2026  
**For:** Offshore Chartering & Vessel Operations System

---

## Overview

This `.gitignore` file ensures that sensitive data, temporary files, and environment-specific configurations are NOT committed to version control.

**Key Principles:**
- Never commit secrets (passwords, API keys, tokens)
- Never commit dependencies (node_modules, vendor)
- Never commit generated files (dist, build, logs)
- Never commit environment-specific files (.env, local configs)

---

## What's Ignored (by Category)

### 🔐 Secrets & Environment

```
.env                    # Local environment variables
.env.local              # Local overrides
.env.*.local            # Per-environment overrides
.env.backup             # Backup of env file (may contain secrets)
.env.production.local   # Production local overrides
.env.test.local         # Test environment variables
.env.development.local  # Development overrides
```

**Why:** These files contain database passwords, API keys, and other secrets that should never be in version control.

---

### 📦 Dependencies

```
backend/vendor/         # Composer packages (PHP)
backend/node_modules/   # npm packages in backend (if any)
frontend/node_modules/  # npm packages (JavaScript)
node_modules/           # Root-level npm packages (catch-all)
```

**Why:** Dependencies are large and can be reinstalled via `composer install` or `npm install` using lock files.

---

### 🛠️ Build Outputs

```
backend/bootstrap/cache/        # Laravel bootstrap cache
backend/storage/                # Laravel storage (logs, cache, sessions)
backend/public/hot              # Vite hot reload file
backend/public/storage          # Symlink to storage
frontend/dist/                  # Frontend production build
frontend/build/                 # Alternative build directory
frontend/.vite/                 # Vite cache
```

**Why:** These are generated at build/runtime and should not be in version control.

---

### 🖥️ IDE & Editor Files

```
.vscode/                        # VS Code workspace settings
.idea/                          # JetBrains IDEs (IntelliJ, PhpStorm)
*.swp / *.swo                   # Vim temporary files
*~                              # Emacs backup files
.sublime-project                # Sublime Text project files
.vim/                           # Vim configuration
.emacs.d/                       # Emacs configuration
```

**Why:** These are personal editor settings and shouldn't force all developers to use the same configuration.

---

### 💾 Cache & Test Files

```
backend/.phpunit.result.cache   # PHPUnit cache
backend/.php_cs.cache           # PHP CS Fixer cache
backend/.php-cs-fixer.cache     # Alternative PHP Fixer cache
backend/.phpstan.cache          # PHPStan analysis cache
backend/.pint.cache             # Pint formatter cache
frontend/.eslintcache           # ESLint cache
frontend/coverage/              # Test coverage reports
frontend/test-results/          # Playwright test results
```

**Why:** Caches and test artifacts are generated locally and can differ between environments.

---

### 🍎 OS Files

```
.DS_Store                       # macOS folder metadata
.AppleDouble                    # macOS resource fork
.LSOverride                     # macOS Finder settings
._*                             # macOS temp files
.Spotlight-V100                 # macOS Spotlight index
.Trashes                        # macOS trash
Thumbs.db                       # Windows thumbnail cache
Desktop.ini                     # Windows folder settings
ehthumbs.db                     # Windows thumbnail database
$RECYCLE.BIN/                   # Windows recycle bin
```

**Why:** OS-specific files should not be version controlled.

---

### 📝 Logs & Temporary Files

```
*.log                           # Log files
logs/                           # Log directory
backend/storage/logs/           # Laravel logs
*.tmp / *.bak / *.backup        # Temporary/backup files
temp/ / tmp/                    # Temporary directories
```

**Why:** Logs are generated at runtime and can contain sensitive information.

---

### 🔑 Secrets Pattern Matching

```
*password*          # Any file with "password" in name
*secret*            # Any file with "secret" in name
*token*             # Any file with "token" in name
*.key               # Private key files
*.pem               # PEM certificate files
*.keystore          # Java keystore files
*.jks               # Java keystore files
```

**Why:** Broad pattern matching catches accidental secret files.

---

### 📦 Package Manager Locks (Optional)

Currently NOT ignored:
- `composer.lock` - Include to ensure exact PHP dependencies
- `package-lock.json` - Include to ensure exact npm versions
- `yarn.lock` - Include if using Yarn
- `pnpm-lock.yaml` - Include if using pnpm

These ARE typically committed because they ensure reproducible builds.

---

## What Should Be Committed

✅ **DO commit:**
- Source code (.php, .tsx, .ts, .css, .html)
- Configuration (config files without secrets)
- Tests (test files and test data)
- Documentation (README, docs/)
- Package manifests (composer.json, package.json)
- Lock files (composer.lock, package-lock.json)
- `.gitignore` itself
- `.gitattributes` (if using)

---

## What Should NEVER Be Committed

❌ **DO NOT commit:**
- `.env` files (use `.env.example` instead)
- Private keys, certificates, credentials
- Database files (*.sqlite, *.db)
- Node modules, vendor packages
- Build outputs (dist, build)
- IDE settings (personal preferences)
- OS system files (.DS_Store, Thumbs.db)
- Log files, caches, temp files

---

## Best Practices

### 1. Use `.env.example`

Create a template for environment variables:

```bash
# File: backend/.env.example
APP_NAME="Offshore"
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=offshore
DB_USERNAME=root
DB_PASSWORD=       # DO NOT FILL IN!
APP_KEY=
JWT_SECRET=
```

**Then:**
```bash
# Developers copy the template
cp backend/.env.example backend/.env
# And fill in their local values
```

### 2. Never Add Secrets to Code

❌ **Bad:**
```php
$password = "SuperSecret123";  // Never do this!
```

✅ **Good:**
```php
$password = env('DB_PASSWORD');  // From .env file
```

### 3. Review Before Committing

```bash
# See what will be committed
git status

# Check for accidental secrets
git diff --cached | grep -i "password\|secret\|token"
```

### 4. Git Pre-commit Hook (Optional)

Prevent secrets from being committed:

```bash
#!/bin/bash
# File: .git/hooks/pre-commit

if git diff --cached | grep -iE "password|secret|token|api.?key" > /dev/null; then
    echo "ERROR: Found potential secret in staged files!"
    exit 1
fi
```

---

## Common Issues & Solutions

### Issue: "I accidentally committed `.env`!"

**Solution:**
```bash
# Remove from git history (dangerous - ask your team first)
git rm --cached .env
git commit --amend --no-edit

# Alternative: Just remove from future commits
git rm --cached .env
echo ".env" >> .gitignore
git add .gitignore
git commit -m "Stop tracking .env file"
```

### Issue: "`node_modules` keeps appearing"

**Solution:**
```bash
# Ensure entry is in .gitignore
echo "node_modules/" >> .gitignore
git rm --cached -r node_modules/
git commit -m "Stop tracking node_modules"
```

### Issue: "IDE files are in git"

**Solution:**
```bash
# Add IDE directories to .gitignore
echo ".vscode/" >> .gitignore
echo ".idea/" >> .gitignore
git rm --cached -r .vscode/ .idea/
git commit -m "Stop tracking IDE files"
```

---

## For New Team Members

**When you clone the repo:**

```bash
# 1. Copy environment template
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env

# 2. Fill in your local values
nano backend/.env      # Edit with your local settings
nano frontend/.env

# 3. Never commit these files
# (they're in .gitignore, so git won't track them)

# 4. Verify git is not tracking .env
git status            # Should NOT show .env files
```

---

## Checking What's Ignored

```bash
# See all files matched by .gitignore
git check-ignore -v *
git check-ignore -v -r .

# Check if a specific file is ignored
git check-ignore -v path/to/file

# See what WOULD be ignored
git status --ignored
```

---

## Common Patterns Explained

| Pattern | Matches | Example |
|---------|---------|---------|
| `node_modules/` | Directory and contents | node_modules/express/lib/... |
| `*.log` | Files with .log extension | error.log, debug.log |
| `.env` | Exact filename | .env (anywhere in repo) |
| `.vscode/` | Directory .vscode | .vscode/settings.json |
| `*password*` | Anything with "password" | password.txt, app_password_store |
| `!important.txt` | Exception - DO track this | Override ignore rule |

---

## Files in This Repo

The current `.gitignore` includes sections for:

1. **IDE & Editor** - All common editors (VS Code, IntelliJ, Vim, Sublime)
2. **OS Files** - macOS (.DS_Store), Windows (Thumbs.db), Linux
3. **Environment & Secrets** - .env files, secrets, tokens
4. **Backend (Laravel)** - Vendor, storage, cache, logs
5. **Frontend (React)** - node_modules, dist, build, .vite
6. **Database** - SQLite files, test databases
7. **Cache & Testing** - PHPUnit cache, ESLint cache, test results
8. **Logs & Temporary** - All log files, temp files
9. **Secrets Pattern** - Catch-all for files with names containing secret words
10. **Lock Files** - Commented out (we WANT to commit these)

---

## Verification

**Check the .gitignore is working:**

```bash
cd /Applications/ServBay/www/offshore

# Should show nothing (or only tracked files)
git status

# Should show files that are ignored
git check-ignore -v backend/.env
git check-ignore -v backend/vendor/*

# Should be ignored
ls -la backend/.env          # File exists
git status | grep ".env"     # Git doesn't list it
```

---

## Maintenance

Review `.gitignore` when:
- New IDE adopted (add to IDE section)
- New build tool added (add output directories)
- New language/framework added (add relevant patterns)
- Team encounters secrets in git (add pattern to prevent it)

**Last reviewed:** October 4, 2026  
**Next review:** When significant tooling changes

---

**Summary:** The `.gitignore` file now properly protects secrets, excludes dependencies and build artifacts, and respects developer preferences. Team members can safely use their preferred tools without polluting the repository.
