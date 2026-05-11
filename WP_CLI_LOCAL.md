# WP-CLI on Windows + Local by Flywheel

How to run WP-CLI from any terminal (Git Bash, PowerShell-via-bash, VS Code, Claude Code) without using Local's "Open Site Shell" each time.

## The problem

Local by Flywheel ships WP-CLI bundled per-site, but only exposes it inside Local's "Open Site Shell". Outside that shell, plain `wp` is not on PATH.

## One-time setup

### 1. Locate Local's bundled PHP and wp-cli.phar

```
C:\Program Files (x86)\Local\resources\extraResources\bin\wp-cli\wp-cli.phar
C:\Program Files (x86)\Local\resources\extraResources\lightning-services\php-<VERSION>\bin\win64\php.exe
```

List installed PHP versions:

```bash
ls "/c/Program Files (x86)/Local/resources/extraResources/lightning-services/" | grep -i php
```

This site (`bricks-playground`) uses the version listed under `~/Local Sites/bricks-playground/conf/php/`.

### 2. Create `~/bin/wp-cli.ini`

```ini
extension_dir = "C:\Program Files (x86)\Local\resources\extraResources\lightning-services\php-8.2.29+0\bin\win64\ext"

extension = php_mbstring.dll
extension = php_openssl.dll
extension = php_curl.dll
extension = php_mysqli.dll
extension = php_pdo_mysql.dll
extension = php_fileinfo.dll
extension = php_zip.dll
extension = php_gd.dll
extension = php_sodium.dll
extension = php_intl.dll

memory_limit = 512M
date.timezone = UTC
```

Update `extension_dir` to match your installed PHP version.

### 3. Create `~/bin/wp` wrapper

```bash
#!/usr/bin/env bash
# WP-CLI wrapper using Local by Flywheel's bundled PHP + wp-cli.phar.

PHP_EXE="C:/Program Files (x86)/Local/resources/extraResources/lightning-services/php-8.2.29+0/bin/win64/php.exe"
WP_CLI_PHAR="C:/Program Files (x86)/Local/resources/extraResources/bin/wp-cli/wp-cli.phar"
PHP_INI="$HOME/bin/wp-cli.ini"

if [ ! -x "$PHP_EXE" ]; then
    echo "wp: bundled PHP not found at $PHP_EXE" >&2
    exit 1
fi

"$PHP_EXE" -c "$PHP_INI" "$WP_CLI_PHAR" "$@"
```

```bash
chmod +x ~/bin/wp
```

### 4. Add `~/bin` to PATH

Append to `~/.bashrc`:

```bash
export PATH="$HOME/bin:$PATH"
```

Reload:

```bash
source ~/.bashrc
```

### 5. Verify

```bash
wp --version
# WP-CLI 2.12.0
```

---

## Common commands for this plugin

```bash
# From the plugin directory
cd "/c/Users/$USER/Local Sites/bricks-playground/app/public/wp-content/plugins/ab-markdown-to-bricks"

wp plugin list
wp plugin activate ab-markdown-to-bricks
wp post list --post_type=abmtb_markdown
wp option get abmtb_settings

# From outside any WP install — use --path
wp --path="/c/Users/$USER/Local Sites/bricks-playground/app/public" plugin list
```

---

## i18n workflow

Regenerate the POT, merge into PO files, compile MO. Requires `msgmerge` and `msgfmt` (Git for Windows includes them).

```bash
# From plugin root
wp i18n make-pot . languages/ab-markdown-to-bricks.pot \
    --domain=ab-markdown-to-bricks \
    --skip-plugins --skip-themes

for po in languages/*.po; do
    msgmerge --update --backup=none "$po" languages/ab-markdown-to-bricks.pot
done

for po in languages/*.po; do
    msgfmt "$po" -o "${po%.po}.mo"
done
```

`--skip-plugins --skip-themes` avoids loading the rest of WordPress just to scan source files.

### Watch for fuzzy entries

`msgmerge` marks similar-but-changed strings `#, fuzzy`. WordPress treats fuzzy entries as **untranslated** (falls back to English). Inspect them after each merge:

```bash
for po in languages/*.po; do
    fuzzy=$(msgattrib --only-fuzzy "$po" 2>/dev/null | grep -c '^msgid ')
    echo "$po: $fuzzy fuzzy"
done
```

---

## Gotchas

### Local upgrades PHP

When Local releases an update with a new bundled PHP, `php-8.2.29+0` becomes stale and the wrapper fails. Fix:

```bash
ls "/c/Program Files (x86)/Local/resources/extraResources/lightning-services/" | grep php
# Update PHP_EXE in ~/bin/wp AND extension_dir in ~/bin/wp-cli.ini
```

To auto-detect (use the newest installed version):

```bash
PHP_DIR=$(ls -d "/c/Program Files (x86)/Local/resources/extraResources/lightning-services"/php-*/ 2>/dev/null | sort -V | tail -1)
PHP_EXE="${PHP_DIR}bin/win64/php.exe"
```

### `unexpected '('` from PHP

PHP's CLI argument parser trips on `(` in `-d` flag values, which is what happens with `extension_dir="C:\Program Files (x86)\..."`. Always use `php -c <ini-file>` rather than `-d`.

### "Error establishing a database connection"

Non-DB commands work (`wp core version`), but `wp option get` / `wp post list` fail with `mysqli_real_connect(): … target machine actively refused it`.

**Cause:** Local binds each site's MySQL to a per-site port on `127.0.0.1` (e.g. `10060`), not `3306`. Each site's `wp-config.php` says `'localhost'`, but resolves to the right port only because Local's per-site `php.ini` overrides `mysqli.default_port`. The generic wrapper ini has no such override → defaults to 3306 → nothing's listening.

**One-shot fix** — use the site's actual php.ini:

```bash
SITE="bricks-playground"
SITE_ID=$(grep -oE "\"id\":\"[^\"]+\"[^}]*\"path\":\"[^\"]*${SITE}\"" \
    "$HOME/AppData/Roaming/Local/sites.json" | grep -oE '"id":"[^"]+"' | head -1 \
    | grep -oE '"[^"]+"$' | tr -d '"')
PHP_DIR=$(ls -d "/c/Program Files (x86)/Local/resources/extraResources/lightning-services"/php-*/ 2>/dev/null | sort -V | tail -1)
PHP_EXE="${PHP_DIR}bin/win64/php.exe"
PHAR="/c/Program Files (x86)/Local/resources/extraResources/bin/wp-cli/wp-cli.phar"
SITE_INI="$HOME/AppData/Roaming/Local/run/${SITE_ID}/conf/php/php.ini"
SITE_PATH="$HOME/Local Sites/${SITE}/app/public"

"$PHP_EXE" -c "$SITE_INI" "$PHAR" --path="$SITE_PATH" option get abmtb_settings
```

The key is `php -c "$SITE_INI"` — the site's rendered php.ini has the correct MySQL port override.

### Skip WP bootstrap for static commands

`wp i18n make-pot` only scans source — it doesn't need WP loaded. Always pass `--skip-plugins --skip-themes` for static-analysis commands so an unrelated plugin error can't break translation updates.

---

## Files this setup creates

| Path | Purpose |
|---|---|
| `~/bin/wp` | Bash wrapper |
| `~/bin/wp-cli.ini` | PHP extension config for WP-CLI |
| `~/.bashrc` | `~/bin` added to PATH |

Removing the setup: `rm ~/bin/wp ~/bin/wp-cli.ini` and revert the `.bashrc` line.

---

## Quick reference

```bash
wp <command>                            # From inside a WP install
wp --path="/c/Users/$USER/Local Sites/bricks-playground/app/public" <command>

# Translations (from plugin root)
wp i18n make-pot . languages/ab-markdown-to-bricks.pot --domain=ab-markdown-to-bricks --skip-plugins --skip-themes
for po in languages/*.po; do msgmerge --update --backup=none "$po" languages/ab-markdown-to-bricks.pot; done
for po in languages/*.po; do msgfmt "$po" -o "${po%.po}.mo"; done
```
