#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# BCC Architecture Guardrails
#
# Enforces the rules documented in CLAUDE.md across all BCC plugins.
# Run before committing.  Exit code 0 = clean, 1 = violations found.
#
# Usage:
#   bash scripts/arch-guardrails.sh              # scan all plugins
#   bash scripts/arch-guardrails.sh bcc-disputes  # scan one plugin
#   bash scripts/arch-guardrails.sh --json        # machine-readable output
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

# ── Resolve plugin root ──────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_DIR="$(cd "$SCRIPT_DIR/../../" && pwd)"

JSON_MODE=false
TARGET=""

for arg in "$@"; do
    case "$arg" in
        --json) JSON_MODE=true ;;
        *)      TARGET="$arg" ;;
    esac
done

# All BCC plugins (order doesn't matter)
ALL_PLUGINS=(
    bcc-core
    bcc-trust-engine
    bcc-disputes
    bcc-onchain-signals
    bcc-search
    blue-collar-crypto-peepso-integration
)

if [[ -n "$TARGET" && "$TARGET" != "--json" ]]; then
    PLUGINS=("$TARGET")
else
    PLUGINS=("${ALL_PLUGINS[@]}")
fi

# ── Counters ─────────────────────────────────────────────────────────────────

TOTAL_VIOLATIONS=0
TOTAL_WARNINGS=0
declare -a JSON_ENTRIES=()

# ── Helpers ──────────────────────────────────────────────────────────────────

RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
BOLD='\033[1m'
NC='\033[0m'

violation() {
    local plugin="$1" rule="$2" file="$3" line="${4:-}" detail="${5:-}"
    TOTAL_VIOLATIONS=$((TOTAL_VIOLATIONS + 1))
    if $JSON_MODE; then
        JSON_ENTRIES+=("{\"severity\":\"ERROR\",\"plugin\":\"$plugin\",\"rule\":\"$rule\",\"file\":\"$file\",\"line\":\"$line\",\"detail\":\"$detail\"}")
    else
        echo -e "  ${RED}VIOLATION${NC} [$rule] $file${line:+:$line} ${detail}"
    fi
}

warning() {
    local plugin="$1" rule="$2" file="$3" line="${4:-}" detail="${5:-}"
    TOTAL_WARNINGS=$((TOTAL_WARNINGS + 1))
    if $JSON_MODE; then
        JSON_ENTRIES+=("{\"severity\":\"WARN\",\"plugin\":\"$plugin\",\"rule\":\"$rule\",\"file\":\"$file\",\"line\":\"$line\",\"detail\":\"$detail\"}")
    else
        echo -e "  ${YELLOW}WARNING${NC}   [$rule] $file${line:+:$line} ${detail}"
    fi
}

# ── Rule 1: $wpdb only in Repositories ───────────────────────────────────────
#
# Allowed locations:
#   app/Repositories/        (the whole point)
#   app/Database/            (TableRegistry — table name helper)
#   app/Security/TransactionManager.php  (BEGIN/COMMIT/ROLLBACK)
#   includes/database/       (schema files, dbDelta)
#   uninstall.php            (cleanup on plugin delete)
#   src/                     (bcc-core internals: Throttle, ChallengeRepository)
#
# Forbidden locations:
#   app/Services/            (must delegate to repositories)
#   app/Controllers/         (must delegate to services)
#   app/Admin/               (must delegate to repositories)
#   templates/               (must receive data, never query)
#   blocks/                  (must receive data, never query)
#   includes/renderers/      (must receive data, never query)

check_wpdb_leaks() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    [[ -d "$plugin_dir" ]] || return

    # Directories where $wpdb is FORBIDDEN
    local -a forbidden_dirs=()
    for d in app/Services app/Controllers app/Admin app/Integration templates blocks includes/renderers includes/partials; do
        [[ -d "$plugin_dir/$d" ]] && forbidden_dirs+=("$plugin_dir/$d")
    done

    [[ ${#forbidden_dirs[@]} -eq 0 ]] && return

    # grep for 'global $wpdb' or '$wpdb->' in forbidden directories
    while IFS=: read -r file line content; do
        # Skip comments (lines starting with optional whitespace + // or *)
        if echo "$content" | grep -qE '^\s*(//|\*|#)'; then
            continue
        fi

        # Normalize path for display
        local rel="${file#$PLUGINS_DIR/}"

        # Known exceptions
        case "$rel" in
            */Security/TransactionManager.php) continue ;;  # BEGIN/COMMIT/ROLLBACK
            */Database/TableRegistry.php)      continue ;;  # table name helper
        esac

        violation "$plugin" "WPDB_OUTSIDE_REPO" "$rel" "$line" "— direct \$wpdb in forbidden layer"
    done < <(grep -rn 'global \$wpdb\|\$wpdb->' "${forbidden_dirs[@]}" --include='*.php' 2>/dev/null || true)
}

# ── Rule 2: No SELECT * ─────────────────────────────────────────────────────

check_select_star() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    [[ -d "$plugin_dir/app/Repositories" ]] || return

    while IFS=: read -r file line content; do
        local rel="${file#$PLUGINS_DIR/}"
        violation "$plugin" "SELECT_STAR" "$rel" "$line" "— use explicit column lists"
    done < <(grep -rn 'SELECT \*' "$plugin_dir/app/Repositories" --include='*.php' 2>/dev/null || true)
}

# ── Rule 3: No template queries ──────────────────────────────────────────────

check_template_queries() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    local -a template_dirs=()
    for d in templates blocks includes/admin includes/partials; do
        [[ -d "$plugin_dir/$d" ]] && template_dirs+=("$plugin_dir/$d")
    done

    [[ ${#template_dirs[@]} -eq 0 ]] && return

    while IFS=: read -r file line content; do
        local rel="${file#$PLUGINS_DIR/}"
        violation "$plugin" "TEMPLATE_QUERY" "$rel" "$line" "— templates must not query DB"
    done < <(grep -rn 'global \$wpdb' "${template_dirs[@]}" --include='*.php' 2>/dev/null || true)
}

# ── Rule 4: Bounded queries (warn on SELECT without LIMIT) ──────────────────

check_unbounded_queries() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    [[ -d "$plugin_dir/app/Repositories" ]] || return

    # Find SELECT statements without LIMIT, WHERE...id, IN(), or aggregate
    # This is a heuristic — it catches obvious cases, not all.
    while IFS=: read -r file line content; do
        local rel="${file#$PLUGINS_DIR/}"
        # Skip if the same file+nearby lines have LIMIT, WHERE...=%d, IN(, COUNT(, SUM(, AVG(
        local context
        context=$(sed -n "$((line > 5 ? line - 5 : 1)),$((line + 10))p" "$file" 2>/dev/null || true)
        if echo "$context" | grep -qi 'LIMIT\|WHERE.*=.*%d\|IN\s*(\|COUNT(\|SUM(\|AVG(\|GROUP BY'; then
            continue
        fi
        warning "$plugin" "UNBOUNDED_SELECT" "$rel" "$line" "— SELECT may be unbounded (no LIMIT or unique key)"
    done < <(grep -rn 'get_results\|get_col' "$plugin_dir/app/Repositories" --include='*.php' 2>/dev/null || true)
}

# ── Rule 5: PHP syntax check on app/ files ───────────────────────────────────

check_php_syntax() {
    local plugin="$1"
    local plugin_dir="$PLUGINS_DIR/$plugin"

    local -a src_dirs=()
    [[ -d "$plugin_dir/app" ]] && src_dirs+=("$plugin_dir/app")
    [[ -d "$plugin_dir/src" ]] && src_dirs+=("$plugin_dir/src")
    [[ ${#src_dirs[@]} -eq 0 ]] && return

    while IFS= read -r file; do
        local result
        result=$(php -l "$file" 2>&1)
        if [[ $? -ne 0 ]]; then
            local rel="${file#$PLUGINS_DIR/}"
            violation "$plugin" "PHP_SYNTAX" "$rel" "" "— $result"
        fi
    done < <(find "${src_dirs[@]}" -name '*.php' 2>/dev/null | head -200)
}

# ── Run all checks ───────────────────────────────────────────────────────────

for plugin in "${PLUGINS[@]}"; do
    plugin_dir="$PLUGINS_DIR/$plugin"
    if [[ ! -d "$plugin_dir" ]]; then
        $JSON_MODE || echo -e "${YELLOW}SKIP${NC} $plugin (not found)"
        continue
    fi

    $JSON_MODE || echo -e "\n${BOLD}Scanning $plugin${NC}"

    check_wpdb_leaks "$plugin"
    check_select_star "$plugin"
    check_template_queries "$plugin"
    check_unbounded_queries "$plugin"
    # PHP syntax check is slow (~2s/file) — enable for single-plugin scans only.
    if [[ -n "$TARGET" ]]; then
        check_php_syntax "$plugin"
    fi

    $JSON_MODE || {
        if [[ $TOTAL_VIOLATIONS -eq 0 && $TOTAL_WARNINGS -eq 0 ]]; then
            echo -e "  ${GREEN}CLEAN${NC}"
        fi
    }
done

# ── Output ───────────────────────────────────────────────────────────────────

if $JSON_MODE; then
    echo "["
    sep=""
    for entry in "${JSON_ENTRIES[@]}"; do
        echo "${sep}  ${entry}"
        sep=","
    done
    echo "]"
else
    echo ""
    echo "──────────────────────────────────────────"
    if [[ $TOTAL_VIOLATIONS -gt 0 ]]; then
        echo -e "${RED}FAIL${NC}: $TOTAL_VIOLATIONS violation(s), $TOTAL_WARNINGS warning(s)"
    elif [[ $TOTAL_WARNINGS -gt 0 ]]; then
        echo -e "${YELLOW}WARN${NC}: 0 violations, $TOTAL_WARNINGS warning(s)"
    else
        echo -e "${GREEN}PASS${NC}: All guardrails satisfied"
    fi
    echo "──────────────────────────────────────────"
fi

exit $( [[ $TOTAL_VIOLATIONS -eq 0 ]] && echo 0 || echo 1 )
