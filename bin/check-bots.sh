#!/bin/bash
echo "==============================================="
echo "     FortKnox IRC Bots - Status Check"
echo "==============================================="

for ID in 1 2 3; do
    echo -e "\n---> Netzwerk $ID:"
    
    # Prüft den Systemd-Status
    STATUS=$(systemctl is-active fortknox-irc@$ID 2>/dev/null)
    if [ "$STATUS" = "active" ]; then
        echo "Status: ONLINE (active)"
    else
        echo "Status: OFFLINE / FEHLER ($STATUS)"
    fi
    
    # Zeigt die letzten relevanten Log-Zeilen
    echo "Logs (letzte Ereignisse):"
    journalctl -u fortknox-irc@$ID -n 30 --no-pager --output=cat | grep -iE 'collision|GHOST|IDENTIFY|Password accepted|NickServ OK|JOIN|error' | tail -n 5
    echo "-----------------------------------------------"
done
