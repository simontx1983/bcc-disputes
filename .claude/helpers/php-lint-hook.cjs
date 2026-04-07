#!/usr/bin/env node
/**
 * PHP Lint Post-Edit Hook
 *
 * Runs `php -l` on PHP files after they are edited.
 * Reads tool result from stdin (Claude Code PostToolUse format).
 * Exits 0 always (non-zero would block Claude Code).
 * Outputs warning to stderr if syntax error found.
 */

const { execSync } = require('child_process');

async function main() {
  let input = '';

  if (!process.stdin.isTTY) {
    input = await new Promise((resolve) => {
      const chunks = [];
      const timeout = setTimeout(() => resolve(chunks.join('')), 2000);
      process.stdin.on('data', (chunk) => chunks.push(chunk.toString()));
      process.stdin.on('end', () => { clearTimeout(timeout); resolve(chunks.join('')); });
      process.stdin.on('error', () => { clearTimeout(timeout); resolve(''); });
    });
  }

  let filePath = '';
  try {
    const data = JSON.parse(input);
    filePath = data.tool_input?.file_path || data.input?.file_path || '';
  } catch {
    // Not JSON or no file_path — skip
  }

  if (!filePath || !filePath.endsWith('.php')) {
    process.exit(0);
  }

  try {
    const result = execSync(`php -l "${filePath}" 2>&1`, {
      timeout: 5000,
      encoding: 'utf8',
    });

    if (result.includes('No syntax errors')) {
      // Silent on success
    } else {
      process.stderr.write(`[PHP Lint] ${result.trim()}\n`);
    }
  } catch (err) {
    const output = (err.stdout || err.message || '').trim();
    if (output && !output.includes('No syntax errors')) {
      process.stderr.write(`[PHP Lint] SYNTAX ERROR in ${filePath}:\n${output}\n`);
    }
  }
}

main().catch(() => {}).finally(() => process.exit(0));
