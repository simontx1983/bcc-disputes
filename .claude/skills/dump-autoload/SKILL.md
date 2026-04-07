---
name: dump-autoload
description: Regenerate Composer optimized classmaps for a BCC plugin
disable-model-invocation: false
---

# Dump Autoload — Regenerate Composer Classmaps

Regenerate the optimized Composer classmap for a specified BCC plugin (or all plugins).

## Usage

- `/dump-autoload` — regenerate for all plugins that have composer.json
- `/dump-autoload bcc-trust-engine` — regenerate for a specific plugin

## Steps

1. Identify which plugin(s) to process (argument or all)
2. For each plugin with a `composer.json`:
   - Run `composer dump-autoload -o` in the plugin directory
   - Verify the generated `vendor/composer/autoload_classmap.php` exists
   - Count the number of classes in the classmap
3. Report results

## Plugin directories

```
c:/Users/simon/Local Sites/blue-collar-crypto-custom/app/public/wp-content/plugins/bcc-core
c:/Users/simon/Local Sites/blue-collar-crypto-custom/app/public/wp-content/plugins/bcc-trust-engine
c:/Users/simon/Local Sites/blue-collar-crypto-custom/app/public/wp-content/plugins/bcc-onchain-signals
c:/Users/simon/Local Sites/blue-collar-crypto-custom/app/public/wp-content/plugins/bcc-disputes
c:/Users/simon/Local Sites/blue-collar-crypto-custom/app/public/wp-content/plugins/bcc-search
c:/Users/simon/Local Sites/blue-collar-crypto-custom/app/public/wp-content/plugins/blue-collar-crypto-peepso-integration
```

## Command per plugin

```bash
cd "$PLUGIN_DIR" && composer dump-autoload -o 2>&1
```

After running, verify by counting entries:
```bash
grep -c "=>" "$PLUGIN_DIR/vendor/composer/autoload_classmap.php"
```
