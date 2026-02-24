#!/usr/bin/env python3
"""
Tests fonctionnels du device Switch ASCOM Alpaca.
À exécuter depuis le container :
    docker exec astropsy-alpaca-1 python3 /app/alpaca_server/test_switch.py
"""

import sys
import urllib.request
import json

BASE = "http://127.0.0.1:11111"


def get(path):
    try:
        with urllib.request.urlopen(BASE + path, timeout=5) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        return json.loads(e.read())


def put(path, data):
    req = urllib.request.Request(BASE + path, data=data.encode(), method="PUT")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        return json.loads(e.read())


def check(label, got, expected):
    ok = got == expected
    status = "OK" if ok else "FAIL"
    print(f"  [{status}] {label}: {got!r}" + ("" if ok else f"  (expected {expected!r})"))
    return ok


errors = 0

# -----------------------------------------------------------------------
print("=== Management ===")
r = get("/management/v1/configureddevices")
types = {d["DeviceType"] for d in r["Value"]}
if not check("SafetyMonitor présent", "SafetyMonitor" in types, True): errors += 1
if not check("Switch présent",        "Switch" in types, True): errors += 1

# -----------------------------------------------------------------------
print("\n=== Switch — interface de base ===")
if not check("maxswitch", get("/api/v1/switch/0/maxswitch")["Value"], 6): errors += 1
if not check("interfaceversion", get("/api/v1/switch/0/interfaceversion")["Value"], 3): errors += 1

# -----------------------------------------------------------------------
print("\n=== Items — lecture initiale ===")
for i in range(6):
    name = get(f"/api/v1/switch/0/getswitchname?Id={i}")["Value"]
    val  = get(f"/api/v1/switch/0/getswitchvalue?Id={i}")["Value"]
    mn   = get(f"/api/v1/switch/0/minswitchvalue?Id={i}")["Value"]
    mx   = get(f"/api/v1/switch/0/maxswitchvalue?Id={i}")["Value"]
    cw   = get(f"/api/v1/switch/0/canwrite?Id={i}")["Value"]
    kind = "switch" if i < 3 else "gauge"
    print(f"  Id={i} [{kind}]  {name!r:22s}  val={val}  range=[{mn},{mx}]  rw={cw}")
    if not check(f"  can_write(Id={i})", cw, True): errors += 1

# -----------------------------------------------------------------------
print("\n=== Switches booléens (Id 0–2) ===")
put("/api/v1/switch/0/connected", "Connected=true")

for i in range(3):
    r = put("/api/v1/switch/0/setswitch", f"Id={i}&State=true")
    if not check(f"setswitch({i}, true) erreur", r["ErrorNumber"], 0): errors += 1
    if not check(f"getswitch({i})", get(f"/api/v1/switch/0/getswitch?Id={i}")["Value"], True): errors += 1
    if not check(f"getswitchvalue({i})", get(f"/api/v1/switch/0/getswitchvalue?Id={i}")["Value"], 1.0): errors += 1

    r = put("/api/v1/switch/0/setswitch", f"Id={i}&State=false")
    if not check(f"setswitch({i}, false) erreur", r["ErrorNumber"], 0): errors += 1
    if not check(f"getswitch({i}) après reset", get(f"/api/v1/switch/0/getswitch?Id={i}")["Value"], False): errors += 1

# -----------------------------------------------------------------------
print("\n=== Jauges (Id 3–5) ===")
for i, val in [(3, 0.0), (4, 42.5), (5, 100.0)]:
    r = put("/api/v1/switch/0/setswitchvalue", f"Id={i}&Value={val}")
    if not check(f"setswitchvalue({i}, {val}) erreur", r["ErrorNumber"], 0): errors += 1
    if not check(f"getswitchvalue({i})", get(f"/api/v1/switch/0/getswitchvalue?Id={i}")["Value"], val): errors += 1

# -----------------------------------------------------------------------
print("\n=== Validations d'erreurs ===")
r = get("/api/v1/switch/0/getswitch?Id=99")
if not check("Id hors plage → ErrorNumber 1025", r["ErrorNumber"], 0x401): errors += 1

r = put("/api/v1/switch/0/setswitchvalue", "Id=3&Value=999")
if not check("Valeur hors plage → ErrorNumber 1025", r["ErrorNumber"], 0x401): errors += 1

r = put("/api/v1/switch/0/setswitch", "Id=1&State=maybe")
if not check("State invalide → ErrorNumber 1025", r["ErrorNumber"], 0x401): errors += 1

# -----------------------------------------------------------------------
print("\n=== SafetyMonitor toujours fonctionnel ===")
r = get("/api/v1/safetymonitor/0/issafe")
print(f"  IsSafe={r['Value']}  ErrorNumber={r['ErrorNumber']}")
if not check("SafetyMonitor ErrorNumber", r["ErrorNumber"], 0): errors += 1

# -----------------------------------------------------------------------
print(f"\n{'='*40}")
if errors == 0:
    print(f"TOUS LES TESTS OK")
else:
    print(f"{errors} TEST(S) EN ECHEC")
    sys.exit(1)
