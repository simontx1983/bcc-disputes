---
name: php-lint
description: Run PHP syntax check on all modified PHP files across BCC plugins
disable-model-invocation: false
---

# PHP Lint — Syntax Check Modified Files

Run `php -l` on all PHP files modified since the last commit across all BCC plugin directories.

## Steps

1. Find all modified PHP files (staged + unstaged) using `git diff --name-only HEAD` in each plugin directory
2. Run `php -l` on each file
3. Report results: pass count, fail count, and details of any failures
4. If all pass, output a single summary line

## Plugin directories to check

```
wp-content/plugins/bcc-core
wp-content/plugins/bcc-trust-engine
wp-content/plugins/bcc-onchain-signals
wp-content/plugins/bcc-disputes
wp-content/plugins/bcc-search
wp-content/plugins/blue-collar-crypto-peepso-integration
```

## Command

```bash
PLUGINS_BASE="c:/Users/simon/Local Sites/blue-collar-crypto-custom/app/public/wp-content/plugins"
PASS=0; FAIL=0; ERRORS=""
for dir in bcc-core bcc-trust-engine bcc-onchain-signals bcc-disputes bcc-search blue-collar-crypto-peepso-integration; do
  PLUGIN_DIR="$PLUGINS_BASE/$dir"
  if [ -d "$PLUGIN_DIR/.git" ] || [ -d "$PLUGIN_DIR" ]; then
    for f in $(cd "$PLUGIN_DIR" && git diff --name-only HEAD 2>/dev/null | grep '\.php$'); do
      FULL="$PLUGIN_DIR/$f"
      if [ -f "$FULL" ]; then
        OUTPUT=$(php -l "$FULL" 2>&1)
        if echo "$OUTPUT" | grep -q "No syntax errors"; then
          PASS=$((PASS + 1))
        else
          FAIL=$((FAIL + 1))
          ERRORS="$ERRORS\n$dir/$f: $OUTPUT"
        fi
      fi
    done
  fi
done
echo "PHP Lint: $PASS passed, $FAIL failed"
if [ $FAIL -gt 0 ]; then echo -e "$ERRORS"; fi
```

Run this command and report the results.
