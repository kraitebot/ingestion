#!/bin/bash
export TELEGRAM_STATE_DIR="$HOME/.claude/channels/telegram-kraite"
cd "$(dirname "$0")"
exec claude --dangerously-skip-permissions --continue --channels plugin:telegram@claude-plugins-official "$@"
