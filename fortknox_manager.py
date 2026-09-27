import os
import subprocess
import pymysql
from datetime import datetime
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel

app = FastAPI(title="FortKnox PreDB Operations API", root_path="/ops")

API_TOKEN = "2f48a7586ef3105af2ab32db9590aa2548562619a9eb422b"
DB_HOST = "127.0.0.1"
DB_USER = "fortknox"
DB_PASS = "35da3f0d6b45a1007e8398584ebbfff9"
DB_NAME = "fortknox"

MANAGED_SERVICES = {
    "bot": "fortknox-ircbot.service",
    "crawler": "fortknox-crawler.service",
    "apache": "apache2.service",
    "mariadb": "mariadb.service"
}

def check_auth(auth_header: str):
    expected = f"Bearer {API_TOKEN}".strip()
    if not auth_header or auth_header.strip() != expected:
        raise HTTPException(status_code=401, detail="Unauthorized")

def get_db_conn():
    return pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        cursorclass=pymysql.cursors.DictCursor
    )

@app.get("/")
def root():
    return {"status": "online", "service": "FortKnox Operations API", "database": DB_NAME}

class ServiceAction(BaseModel):
    service: str
    action: str

@app.post("/service/manage")
def manage_service(req: ServiceAction, authorization: str = Header(None)):
    check_auth(authorization)
    if req.service not in MANAGED_SERVICES:
        raise HTTPException(status_code=400, detail="Service nicht verwaltet")
    if req.action not in ["status", "restart", "start", "stop"]:
        raise HTTPException(status_code=400, detail="Aktion unzulässig")

    unit = MANAGED_SERVICES[req.service]
    res = subprocess.run(["systemctl", req.action, unit], capture_output=True, text=True)
    return {"unit": unit, "action": req.action, "exit_code": res.returncode, "output": res.stdout or res.stderr}

@app.get("/system/predb-health")
def predb_health(authorization: str = Header(None)):
    check_auth(authorization)
    try:
        conn = get_db_conn()
        with conn.cursor() as cur:
            # Gesamtzahl Releases
            cur.execute("SELECT COUNT(*) AS total FROM releases")
            total = cur.fetchone()["total"]

            # Letztes Release mit korrekten Spaltennamen
            cur.execute("""
                SELECT id, name, category, source, created_at 
                FROM releases 
                ORDER BY id DESC 
                LIMIT 1
            """)
            latest = cur.fetchone()
            if latest and isinstance(latest.get("created_at"), datetime):
                latest["created_at"] = latest["created_at"].strftime("%Y-%m-%d %H:%M:%S")

            # Releases der letzten Stunde (SQL Timestamp-Vergleich)
            cur.execute("""
                SELECT COUNT(*) AS count_1h 
                FROM releases 
                WHERE created_at >= NOW() - INTERVAL 1 HOUR
            """)
            recents = cur.fetchone()

            return {
                "database_online": True,
                "database_name": DB_NAME,
                "total_releases": total,
                "releases_last_hour": recents["count_1h"] if recents else 0,
                "latest_release": latest
            }
    except Exception as e:
        return {"database_online": False, "database_name": DB_NAME, "error": str(e)}

@app.get("/logs/tail")
def tail_logs(target: str, lines: int = 30, authorization: str = Header(None)):
    check_auth(authorization)
    if target in MANAGED_SERVICES:
        res = subprocess.run(["journalctl", "-u", MANAGED_SERVICES[target], "-n", str(lines), "--no-pager"], capture_output=True, text=True)
        return {"source": target, "lines": res.stdout}
    raise HTTPException(status_code=400, detail="Log-Quelle unbekannt")
