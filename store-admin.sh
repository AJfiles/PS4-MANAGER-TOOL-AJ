#!/data/data/com.termux/files/usr/bin/bash
if [ -f "$HOME/PS4ManagerToolsAJ/store/admin.sh" ]; then exec bash "$HOME/PS4ManagerToolsAJ/store/admin.sh" "$@"; fi
exec bash "$HOME/GoldHenManagerAJ/store/admin.sh" "$@"
