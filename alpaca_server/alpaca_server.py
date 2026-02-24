#!/usr/bin/env python3
"""
ASCOM Alpaca Server — SafetyMonitor + Switch (AstroPsy)
"""

import dataclasses
import json
import logging
import os
import socket
import threading
import urllib.request
from dataclasses import dataclass, asdict, field
from datetime import datetime, timezone
from typing import Optional, Any

from flask import Flask, request, jsonify, redirect
from flask_cors import CORS

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# ASCOM Error Codes
ERROR_SUCCESS         = 0
ERROR_NOT_IMPLEMENTED = 0x400
ERROR_INVALID_VALUE   = 0x401
ERROR_NOT_CONNECTED   = 0x407
ERROR_UNSPECIFIED     = 0x500

ALL_CLOUD_CONDITIONS = [
    'Clear',
    'Wisps of clouds',
    'Mostly Cloudy',
    'Overcast',
    'Rain',
    'Snow',
]


# ---------------------------------------------------------------------------
# Module-level ASCOM helpers (shared across all devices)
# ---------------------------------------------------------------------------

_server_tx_id = 0
_server_tx_lock = threading.Lock()


def _next_tx() -> int:
    global _server_tx_id
    with _server_tx_lock:
        _server_tx_id += 1
        if _server_tx_id > 4294967295:
            _server_tx_id = 1
        return _server_tx_id


def _req_arg(key: str, default: Any = None) -> Any:
    """Case-insensitive lookup in request values (GET params + form data)."""
    key_lower = key.lower()
    for k, v in request.values.items():
        if k.lower() == key_lower:
            return v
    return default


def _req_client_params() -> tuple:
    client_id_raw = str(_req_arg('ClientID', '0')).strip()
    client_tx_raw = str(_req_arg('ClientTransactionID', '0')).strip()
    try:
        client_id = int(client_id_raw) if client_id_raw else 0
    except (ValueError, TypeError):
        client_id = 0
    try:
        client_tx_id = int(client_tx_raw) if client_tx_raw else 0
        if not (0 <= client_tx_id <= 4294967295):
            client_tx_id = 0
    except (ValueError, TypeError):
        client_tx_id = 0
    return client_id, client_tx_id


def _make_response(value: Any = None, error_number: int = ERROR_SUCCESS,
                   error_message: str = "", client_tx_id: int = 0) -> dict:
    resp = {
        "ClientTransactionID": client_tx_id,
        "ServerTransactionID": _next_tx(),
        "ErrorNumber":  error_number,
        "ErrorMessage": error_message,
    }
    if value is not None or error_number == ERROR_SUCCESS:
        resp["Value"] = value
    return resp


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

@dataclass
class AlpacaConfig:
    port: int = 11111
    device_number: int = 0
    device_name: str = "AstroPsy"
    device_description: str = "ASCOM Alpaca SafetyMonitor"
    driver_info: str = "ASCOM Alpaca SafetyMonitor - AstroPsy"
    driver_version: str = "2.0"
    interface_version: int = 3
    update_interval: int = 60
    location: str = "AstroPsy"
    unsafe_conditions: list = field(default_factory=lambda: ['Rain', 'Snow', 'Mostly Cloudy', 'Overcast'])
    # Observatory location — required for forecast
    latitude: float = 0.0
    longitude: float = 0.0
    elevation: int = 0
    timezone: str = "UTC"
    forecast_url: str = "http://localhost:8000/astro/forecast"

    def save_to_file(self, filepath: str = "alpaca_config.json"):
        try:
            with open(filepath, 'w') as f:
                json.dump(asdict(self), f, indent=2)
            logger.info(f"Configuration saved to {filepath}")
        except Exception as e:
            logger.error(f"Failed to save configuration: {e}")

    @classmethod
    def load_from_file(cls, filepath: str = "alpaca_config.json"):
        try:
            if os.path.exists(filepath):
                with open(filepath, 'r') as f:
                    config_dict = json.load(f)
                # Ignore unknown keys; missing keys use dataclass defaults
                valid = {f.name for f in dataclasses.fields(cls)}
                filtered = {k: v for k, v in config_dict.items() if k in valid}
                logger.info(f"Configuration loaded from {filepath}")
                return cls(**filtered)
        except Exception as e:
            logger.error(f"Failed to load configuration: {e}")
        return None


# ---------------------------------------------------------------------------
# Forecast helpers
# ---------------------------------------------------------------------------

def _map_condition(cloud_total, precip_mm, temperature) -> str:
    """Map numeric forecast values to a named cloud condition."""
    if precip_mm and precip_mm > 0:
        return 'Snow' if temperature is not None and temperature <= 2 else 'Rain'
    if cloud_total is None:
        return 'Clear'
    if cloud_total < 10:
        return 'Clear'
    if cloud_total < 30:
        return 'Wisps of clouds'
    if cloud_total < 70:
        return 'Mostly Cloudy'
    return 'Overcast'


def _find_current_point(series: list) -> Optional[dict]:
    """Return the series point whose timestamp is closest to now."""
    if not series:
        return None
    now = datetime.now(timezone.utc)
    best, best_diff = None, None
    for point in series:
        t_str = point.get("t")
        if not t_str:
            continue
        try:
            t = datetime.fromisoformat(t_str)
            if t.tzinfo is None:
                t = t.replace(tzinfo=timezone.utc)
            diff = abs((t - now).total_seconds())
            if best_diff is None or diff < best_diff:
                best_diff, best = diff, point
        except Exception:
            continue
    return best


# ---------------------------------------------------------------------------
# Safety Monitor
# ---------------------------------------------------------------------------

class AlpacaSafetyMonitor:
    def __init__(self, alpaca_config: AlpacaConfig):
        self.alpaca_config = alpaca_config
        self.connected = False
        self.connecting = False

        # Weather state — updated by background thread, fail-safe default
        self._weather_lock = threading.Lock()
        self._is_safe_weather: bool = False
        self._current_condition: str = "Unknown"
        self._last_updated: Optional[datetime] = None
        self._stop_event = threading.Event()

        logger.info(f"Initialized {self.alpaca_config.device_name}")

    # ------------------------------------------------------------------
    # ASCOM helpers (delegate to module-level functions)
    # ------------------------------------------------------------------

    def get_next_transaction_id(self) -> int:
        return _next_tx()

    def create_response(self, value: Any = None, error_number: int = ERROR_SUCCESS,
                        error_message: str = "", client_transaction_id: int = 0) -> dict:
        return _make_response(value, error_number, error_message, client_transaction_id)

    def get_client_params(self) -> tuple:
        return _req_client_params()

    def _get_arg(self, key: str, default: Any = None) -> Any:
        return _req_arg(key, default)

    # ------------------------------------------------------------------
    # Connection
    # ------------------------------------------------------------------

    def connect(self):
        if not self.connected:
            self.connected = True
            logger.info("Connected to safety monitor")

    def disconnect(self):
        if self.connected:
            self.connected = False
            logger.info("Disconnected from safety monitor")

    # ------------------------------------------------------------------
    # Safety logic
    # ------------------------------------------------------------------

    def is_safe(self) -> bool:
        if not self.connected:
            return False
        with self._weather_lock:
            return self._is_safe_weather

    def get_device_state(self) -> list:
        with self._weather_lock:
            return [
                {"Name": "IsSafe",    "Value": self._is_safe_weather},
                {"Name": "Condition", "Value": self._current_condition},
            ]

    # ------------------------------------------------------------------
    # Background weather updates
    # ------------------------------------------------------------------

    def _fetch_and_evaluate(self):
        cfg = self.alpaca_config
        if cfg.latitude == 0.0 and cfg.longitude == 0.0:
            logger.warning("Observatory coordinates not configured — skipping forecast fetch")
            return

        url = (
            f"{cfg.forecast_url}"
            f"?lat={cfg.latitude}&lon={cfg.longitude}"
            f"&elevation={cfg.elevation}&tz={cfg.timezone}&cache=true"
        )
        try:
            with urllib.request.urlopen(url, timeout=15) as resp:
                data = json.loads(resp.read().decode())

            point = _find_current_point(data.get("series", []))
            if not point:
                logger.warning("Forecast returned an empty series")
                return

            condition = _map_condition(
                point.get("cloud_total"),
                point.get("precip_mm"),
                point.get("temperature"),
            )
            safe = condition not in cfg.unsafe_conditions

            with self._weather_lock:
                self._current_condition = condition
                self._is_safe_weather   = safe
                self._last_updated      = datetime.now(timezone.utc)

            logger.info(
                f"Forecast — condition={condition}, safe={safe}, "
                f"cloud={point.get('cloud_total')}%"
            )
        except Exception as e:
            logger.error(f"Forecast fetch failed: {e}")
            # Keep previous state; initial state is False (fail-safe)

    def _weather_loop(self):
        logger.info("Weather update loop started")
        while not self._stop_event.is_set():
            self._fetch_and_evaluate()
            self._stop_event.wait(self.alpaca_config.update_interval)

    def start_weather_updates(self):
        self._stop_event.clear()
        threading.Thread(
            target=self._weather_loop,
            daemon=True,
            name="weather-updater",
        ).start()
        logger.info(f"Weather updater started (interval={self.alpaca_config.update_interval}s)")

    def stop_weather_updates(self):
        self._stop_event.set()


# ---------------------------------------------------------------------------
# Switch Device
# ---------------------------------------------------------------------------

@dataclass
class SwitchItem:
    id: int
    name: str
    description: str
    is_boolean: bool      # True = switch (on/off), False = gauge (float)
    value: float = 0.0
    min_value: float = 0.0
    max_value: float = 1.0
    step: float = 1.0
    can_write: bool = True

    def to_dict(self) -> dict:
        return {
            "name":        self.name,
            "description": self.description,
            "is_boolean":  self.is_boolean,
            "value":       self.value,
            "min_value":   self.min_value,
            "max_value":   self.max_value,
            "step":        self.step,
            "can_write":   self.can_write,
        }

    @classmethod
    def from_dict(cls, id_: int, d: dict) -> 'SwitchItem':
        return cls(
            id=id_,
            name=d.get("name", f"Item {id_}"),
            description=d.get("description", ""),
            is_boolean=bool(d.get("is_boolean", True)),
            value=float(d.get("value", 0.0)),
            min_value=float(d.get("min_value", 0.0)),
            max_value=float(d.get("max_value", 1.0)),
            step=float(d.get("step", 1.0)),
            can_write=bool(d.get("can_write", True)),
        )


class AlpacaSwitch:
    """
    ASCOM Alpaca Switch device.
    Items 0-2 : boolean switches (on/off).
    Items 3-5 : analog gauges   (float, 0–100).
    All items are R/W.
    """
    DEVICE_NUMBER = 0

    def __init__(self):
        self.connected = False
        self.connecting = False
        self._lock = threading.Lock()
        self._items: list[SwitchItem] = [
            SwitchItem(0, "Switch 1", "Interrupteur générique 1",  True,  0.0, 0.0,   1.0, 1.0),
            SwitchItem(1, "Switch 2", "Interrupteur générique 2",  True,  0.0, 0.0,   1.0, 1.0),
            SwitchItem(2, "Switch 3", "Interrupteur générique 3",  True,  0.0, 0.0,   1.0, 1.0),
            SwitchItem(3, "Gauge 1",  "Jauge analogique 1",        False, 0.0, 0.0, 100.0, 0.1),
            SwitchItem(4, "Gauge 2",  "Jauge analogique 2",        False, 0.0, 0.0, 100.0, 0.1),
            SwitchItem(5, "Gauge 3",  "Jauge analogique 3",        False, 0.0, 0.0, 100.0, 0.1),
        ]
        logger.info("AlpacaSwitch initialized (3 switches, 3 gauges)")

    def connect(self):
        if not self.connected:
            self.connected = True
            logger.info("Switch device connected")

    def disconnect(self):
        if self.connected:
            self.connected = False
            logger.info("Switch device disconnected")

    def max_switch(self) -> int:
        return len(self._items)

    def _item(self, id_: int) -> Optional[SwitchItem]:
        with self._lock:
            if 0 <= id_ < len(self._items):
                return self._items[id_]
        return None

    def can_write(self, id_: int) -> bool:
        item = self._item(id_)
        return item.can_write if item else False

    def get_switch(self, id_: int) -> bool:
        item = self._item(id_)
        if item is None:
            raise ValueError(f"Invalid Id: {id_}")
        return item.value != 0.0

    def get_switch_value(self, id_: int) -> float:
        item = self._item(id_)
        if item is None:
            raise ValueError(f"Invalid Id: {id_}")
        return item.value

    def get_switch_name(self, id_: int) -> str:
        item = self._item(id_)
        if item is None:
            raise ValueError(f"Invalid Id: {id_}")
        return item.name

    def get_switch_description(self, id_: int) -> str:
        item = self._item(id_)
        if item is None:
            raise ValueError(f"Invalid Id: {id_}")
        return item.description

    def min_switch_value(self, id_: int) -> float:
        item = self._item(id_)
        if item is None:
            raise ValueError(f"Invalid Id: {id_}")
        return item.min_value

    def max_switch_value(self, id_: int) -> float:
        item = self._item(id_)
        if item is None:
            raise ValueError(f"Invalid Id: {id_}")
        return item.max_value

    def switch_step(self, id_: int) -> float:
        item = self._item(id_)
        if item is None:
            raise ValueError(f"Invalid Id: {id_}")
        return item.step

    def set_switch(self, id_: int, state: bool):
        with self._lock:
            if id_ < 0 or id_ >= len(self._items):
                raise ValueError(f"Invalid Id: {id_}")
            item = self._items[id_]
            if not item.can_write:
                raise PermissionError(f"Switch {id_} is read-only")
            item.value = 1.0 if state else 0.0
            logger.info(f"Switch[{id_}] ({item.name}) → {state}")

    def set_switch_value(self, id_: int, value: float):
        with self._lock:
            if id_ < 0 or id_ >= len(self._items):
                raise ValueError(f"Invalid Id: {id_}")
            item = self._items[id_]
            if not item.can_write:
                raise PermissionError(f"Switch {id_} is read-only")
            if value < item.min_value or value > item.max_value:
                raise ValueError(
                    f"Value {value} out of range [{item.min_value}, {item.max_value}]"
                )
            item.value = value
            logger.info(f"Switch[{id_}] ({item.name}) → {value}")

    def get_device_state(self) -> list:
        with self._lock:
            return [{"Name": item.name, "Value": item.value} for item in self._items]

    def items_snapshot(self) -> list[SwitchItem]:
        """Return a shallow copy of the items list (for templates)."""
        with self._lock:
            return list(self._items)

    def update_item(self, id_: int, *, name: str = None, description: str = None,
                    value: float = None, min_value: float = None,
                    max_value: float = None, step: float = None):
        with self._lock:
            if id_ < 0 or id_ >= len(self._items):
                raise ValueError(f"Invalid Id: {id_}")
            item = self._items[id_]
            if name        is not None: item.name        = name
            if description is not None: item.description = description
            if min_value   is not None: item.min_value   = min_value
            if max_value   is not None: item.max_value   = max_value
            if step        is not None: item.step        = step
            if value       is not None:
                item.value = max(item.min_value, min(item.max_value, value))

    def add_item(self, name: str, description: str, is_boolean: bool,
                 min_value: float = 0.0, max_value: float = 1.0, step: float = 1.0):
        with self._lock:
            id_ = len(self._items)
            self._items.append(SwitchItem(
                id=id_, name=name, description=description,
                is_boolean=is_boolean, value=0.0,
                min_value=min_value, max_value=max_value, step=step,
            ))
        logger.info(f"Switch item added: Id={id_} {name!r} ({'switch' if is_boolean else 'gauge'})")

    def remove_item(self, id_: int):
        with self._lock:
            if 0 <= id_ < len(self._items):
                removed = self._items.pop(id_)
                for i, item in enumerate(self._items):
                    item.id = i
                logger.info(f"Switch item {id_} ('{removed.name}') removed")

    def save_to_file(self, filepath: str = "switch_config.json"):
        try:
            with self._lock:
                data = {"items": [item.to_dict() for item in self._items]}
            with open(filepath, 'w') as f:
                json.dump(data, f, indent=2)
            logger.info(f"Switch config saved to {filepath} ({len(data['items'])} items)")
        except Exception as e:
            logger.error(f"Failed to save switch config: {e}")

    def load_from_file(self, filepath: str = "switch_config.json") -> bool:
        try:
            if os.path.exists(filepath):
                with open(filepath, 'r') as f:
                    data = json.load(f)
                items = [SwitchItem.from_dict(i, d)
                         for i, d in enumerate(data.get("items", []))]
                with self._lock:
                    self._items = items
                logger.info(f"Switch config loaded from {filepath} ({len(items)} items)")
                return True
        except Exception as e:
            logger.error(f"Failed to load switch config: {e}")
        return False


# ---------------------------------------------------------------------------
# Flask application
# ---------------------------------------------------------------------------

app = Flask(__name__)
CORS(app)

safety_monitor: Optional[AlpacaSafetyMonitor] = None
switch_device:  Optional[AlpacaSwitch] = None
discovery_service: Optional['AlpacaDiscovery'] = None


# ---------------------------------------------------------------------------
# Safety Monitor routes
# ---------------------------------------------------------------------------

def validate_device_number(device_number: int) -> Optional[tuple]:
    if device_number != safety_monitor.alpaca_config.device_number:
        _, client_tx_id = safety_monitor.get_client_params()
        return jsonify(safety_monitor.create_response(
            error_number=ERROR_INVALID_VALUE,
            error_message=f"Invalid device number: {device_number}",
            client_transaction_id=client_tx_id,
        )), 400
    return None


def create_simple_get_endpoint(attribute_getter):
    def endpoint(device_number: int):
        error_response = validate_device_number(device_number)
        if error_response:
            return error_response
        _, client_tx_id = safety_monitor.get_client_params()
        return jsonify(safety_monitor.create_response(
            value=attribute_getter(),
            client_transaction_id=client_tx_id,
        ))
    return endpoint


@app.route('/api/v1/safetymonitor/<int:device_number>/issafe', methods=['GET'])
def get_issafe(device_number: int):
    error_response = validate_device_number(device_number)
    if error_response:
        return error_response
    _, client_tx_id = safety_monitor.get_client_params()
    return jsonify(safety_monitor.create_response(
        value=safety_monitor.is_safe(),
        client_transaction_id=client_tx_id,
    ))


@app.route('/api/v1/safetymonitor/<int:device_number>/connected', methods=['GET'])
def get_connected(device_number: int):
    error_response = validate_device_number(device_number)
    if error_response:
        return error_response
    _, client_tx_id = safety_monitor.get_client_params()
    return jsonify(safety_monitor.create_response(
        value=safety_monitor.connected,
        client_transaction_id=client_tx_id,
    ))


@app.route('/api/v1/safetymonitor/<int:device_number>/connected', methods=['PUT'])
def set_connected(device_number: int):
    error_response = validate_device_number(device_number)
    if error_response:
        return error_response
    _, client_tx_id = safety_monitor.get_client_params()
    connected_str = safety_monitor._get_arg('Connected', '').strip().lower()
    if connected_str == 'true':
        target_state = True
    elif connected_str == 'false':
        target_state = False
    else:
        return jsonify(safety_monitor.create_response(
            error_number=ERROR_INVALID_VALUE,
            error_message=f"Invalid boolean value for Connected: '{connected_str}'",
            client_transaction_id=client_tx_id,
        )), 400
    try:
        if target_state != safety_monitor.connected:
            safety_monitor.connect() if target_state else safety_monitor.disconnect()
        return jsonify(safety_monitor.create_response(client_transaction_id=client_tx_id))
    except Exception as e:
        logger.error(f"Failed to set connected state: {e}")
        return jsonify(safety_monitor.create_response(
            error_number=ERROR_UNSPECIFIED, error_message=str(e),
            client_transaction_id=client_tx_id,
        )), 500


@app.route('/api/v1/safetymonitor/<int:device_number>/connecting', methods=['GET'])
def get_connecting(device_number: int):
    error_response = validate_device_number(device_number)
    if error_response:
        return error_response
    _, client_tx_id = safety_monitor.get_client_params()
    return jsonify(safety_monitor.create_response(
        value=safety_monitor.connecting,
        client_transaction_id=client_tx_id,
    ))


@app.route('/api/v1/safetymonitor/<int:device_number>/connect', methods=['PUT'])
def connect_device(device_number: int):
    error_response = validate_device_number(device_number)
    if error_response:
        return error_response
    _, client_tx_id = safety_monitor.get_client_params()
    try:
        if not safety_monitor.connected:
            safety_monitor.connect()
        return jsonify(safety_monitor.create_response(client_transaction_id=client_tx_id))
    except Exception as e:
        return jsonify(safety_monitor.create_response(
            error_number=ERROR_UNSPECIFIED, error_message=str(e),
            client_transaction_id=client_tx_id,
        )), 500


@app.route('/api/v1/safetymonitor/<int:device_number>/disconnect', methods=['PUT'])
def disconnect_device(device_number: int):
    error_response = validate_device_number(device_number)
    if error_response:
        return error_response
    _, client_tx_id = safety_monitor.get_client_params()
    try:
        if safety_monitor.connected:
            safety_monitor.disconnect()
        return jsonify(safety_monitor.create_response(client_transaction_id=client_tx_id))
    except Exception as e:
        return jsonify(safety_monitor.create_response(
            error_number=ERROR_UNSPECIFIED, error_message=str(e),
            client_transaction_id=client_tx_id,
        )), 500


@app.route('/api/v1/safetymonitor/<int:device_number>/description', methods=['GET'])
def get_description(device_number: int):
    return create_simple_get_endpoint(
        lambda: safety_monitor.alpaca_config.device_description)(device_number)


@app.route('/api/v1/safetymonitor/<int:device_number>/devicestate', methods=['GET'])
def get_devicestate(device_number: int):
    return create_simple_get_endpoint(safety_monitor.get_device_state)(device_number)


@app.route('/api/v1/safetymonitor/<int:device_number>/driverinfo', methods=['GET'])
def get_driverinfo(device_number: int):
    return create_simple_get_endpoint(
        lambda: safety_monitor.alpaca_config.driver_info)(device_number)


@app.route('/api/v1/safetymonitor/<int:device_number>/driverversion', methods=['GET'])
def get_driverversion(device_number: int):
    return create_simple_get_endpoint(
        lambda: safety_monitor.alpaca_config.driver_version)(device_number)


@app.route('/api/v1/safetymonitor/<int:device_number>/interfaceversion', methods=['GET'])
def get_interfaceversion(device_number: int):
    return create_simple_get_endpoint(
        lambda: safety_monitor.alpaca_config.interface_version)(device_number)


@app.route('/api/v1/safetymonitor/<int:device_number>/name', methods=['GET'])
def get_name(device_number: int):
    return create_simple_get_endpoint(
        lambda: safety_monitor.alpaca_config.device_name)(device_number)


@app.route('/api/v1/safetymonitor/<int:device_number>/supportedactions', methods=['GET'])
def get_supportedactions(device_number: int):
    return create_simple_get_endpoint(lambda: [])(device_number)


@app.route('/api/v1/safetymonitor/<int:device_number>/action', methods=['PUT'])
def put_action(device_number: int):
    error_response = validate_device_number(device_number)
    if error_response:
        return error_response
    _, client_tx_id = safety_monitor.get_client_params()
    return jsonify(safety_monitor.create_response(
        error_number=ERROR_NOT_IMPLEMENTED,
        error_message="No custom actions are supported",
        client_transaction_id=client_tx_id,
    )), 200


@app.route('/api/v1/safetymonitor/<int:device_number>/commandblind', methods=['PUT'])
@app.route('/api/v1/safetymonitor/<int:device_number>/commandbool', methods=['PUT'])
@app.route('/api/v1/safetymonitor/<int:device_number>/commandstring', methods=['PUT'])
def deprecated_commands(device_number: int):
    _, client_tx_id = safety_monitor.get_client_params()
    return jsonify(safety_monitor.create_response(
        error_number=ERROR_NOT_IMPLEMENTED,
        error_message="This method is deprecated",
        client_transaction_id=client_tx_id,
    )), 200


# ---------------------------------------------------------------------------
# Switch routes
# ---------------------------------------------------------------------------

def _validate_sw_device(device_number: int):
    """Returns an error response tuple if device_number is invalid, else None."""
    if device_number != AlpacaSwitch.DEVICE_NUMBER:
        _, tid = _req_client_params()
        return jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE,
            error_message=f"Invalid device number: {device_number}",
            client_tx_id=tid,
        )), 400
    return None


def _parse_switch_id(client_tx_id: int):
    """
    Parse the 'Id' request parameter and validate range.
    Returns (id_int, None) on success, or (None, error_response_tuple) on failure.
    """
    raw = _req_arg('Id', None)
    if raw is None:
        return None, (jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE,
            error_message="Missing required parameter: Id",
            client_tx_id=client_tx_id,
        )), 400)
    try:
        id_ = int(str(raw).strip())
    except (ValueError, TypeError):
        return None, (jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE,
            error_message=f"Invalid Id value: '{raw}'",
            client_tx_id=client_tx_id,
        )), 400)
    max_id = switch_device.max_switch() - 1
    if id_ < 0 or id_ > max_id:
        return None, (jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE,
            error_message=f"Id {id_} out of range [0, {max_id}]",
            client_tx_id=client_tx_id,
        )), 400)
    return id_, None


# --- Common device interface ---

@app.route('/api/v1/switch/<int:device_number>/connected', methods=['GET'])
def sw_get_connected(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value=switch_device.connected, client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/connected', methods=['PUT'])
def sw_set_connected(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    val = str(_req_arg('Connected', '')).strip().lower()
    if val == 'true':
        switch_device.connect()
    elif val == 'false':
        switch_device.disconnect()
    else:
        return jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE,
            error_message=f"Invalid boolean value: '{val}'",
            client_tx_id=tid,
        )), 400
    return jsonify(_make_response(client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/connect', methods=['PUT'])
def sw_connect(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    switch_device.connect()
    return jsonify(_make_response(client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/disconnect', methods=['PUT'])
def sw_disconnect(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    switch_device.disconnect()
    return jsonify(_make_response(client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/connecting', methods=['GET'])
def sw_get_connecting(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value=switch_device.connecting, client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/description', methods=['GET'])
def sw_get_description(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value="ASCOM Alpaca Switch - AstroPsy", client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/driverinfo', methods=['GET'])
def sw_get_driverinfo(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value="AstroPsy Switch Device v1.0", client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/driverversion', methods=['GET'])
def sw_get_driverversion(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value="1.0", client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/interfaceversion', methods=['GET'])
def sw_get_interfaceversion(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value=3, client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/name', methods=['GET'])
def sw_get_name(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value="AstroPsy Switch", client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/supportedactions', methods=['GET'])
def sw_get_supportedactions(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value=[], client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/devicestate', methods=['GET'])
def sw_get_devicestate(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value=switch_device.get_device_state(), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/action', methods=['PUT'])
def sw_put_action(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(
        error_number=ERROR_NOT_IMPLEMENTED,
        error_message="No custom actions supported",
        client_tx_id=tid,
    ))


@app.route('/api/v1/switch/<int:device_number>/commandblind', methods=['PUT'])
@app.route('/api/v1/switch/<int:device_number>/commandbool', methods=['PUT'])
@app.route('/api/v1/switch/<int:device_number>/commandstring', methods=['PUT'])
def sw_deprecated_commands(device_number: int):
    _, tid = _req_client_params()
    return jsonify(_make_response(
        error_number=ERROR_NOT_IMPLEMENTED,
        error_message="This method is deprecated",
        client_tx_id=tid,
    ))


# --- Switch-specific endpoints ---

@app.route('/api/v1/switch/<int:device_number>/maxswitch', methods=['GET'])
def sw_maxswitch(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    return jsonify(_make_response(value=switch_device.max_switch(), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/canwrite', methods=['GET'])
def sw_canwrite(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    return jsonify(_make_response(value=switch_device.can_write(id_), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/getswitch', methods=['GET'])
def sw_getswitch(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    return jsonify(_make_response(value=switch_device.get_switch(id_), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/getswitchname', methods=['GET'])
def sw_getswitchname(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    return jsonify(_make_response(value=switch_device.get_switch_name(id_), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/getswitchdescription', methods=['GET'])
def sw_getswitchdescription(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    return jsonify(_make_response(value=switch_device.get_switch_description(id_), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/getswitchvalue', methods=['GET'])
def sw_getswitchvalue(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    return jsonify(_make_response(value=switch_device.get_switch_value(id_), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/minswitchvalue', methods=['GET'])
def sw_minswitchvalue(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    return jsonify(_make_response(value=switch_device.min_switch_value(id_), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/maxswitchvalue', methods=['GET'])
def sw_maxswitchvalue(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    return jsonify(_make_response(value=switch_device.max_switch_value(id_), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/switchstep', methods=['GET'])
def sw_switchstep(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    return jsonify(_make_response(value=switch_device.switch_step(id_), client_tx_id=tid))


@app.route('/api/v1/switch/<int:device_number>/setswitch', methods=['PUT'])
def sw_setswitch(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    state_raw = str(_req_arg('State', '')).strip().lower()
    if state_raw == 'true':
        state = True
    elif state_raw == 'false':
        state = False
    else:
        return jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE,
            error_message=f"Invalid boolean value for State: '{state_raw}'",
            client_tx_id=tid,
        )), 400
    try:
        switch_device.set_switch(id_, state)
        return jsonify(_make_response(client_tx_id=tid))
    except (ValueError, PermissionError) as e:
        return jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE, error_message=str(e), client_tx_id=tid,
        )), 400


@app.route('/api/v1/switch/<int:device_number>/setswitchvalue', methods=['PUT'])
def sw_setswitchvalue(device_number: int):
    err = _validate_sw_device(device_number)
    if err: return err
    _, tid = _req_client_params()
    id_, id_err = _parse_switch_id(tid)
    if id_err: return id_err
    value_raw = _req_arg('Value', None)
    if value_raw is None:
        return jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE,
            error_message="Missing required parameter: Value",
            client_tx_id=tid,
        )), 400
    try:
        value = float(str(value_raw).strip())
    except (ValueError, TypeError):
        return jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE,
            error_message=f"Invalid numeric value: '{value_raw}'",
            client_tx_id=tid,
        )), 400
    try:
        switch_device.set_switch_value(id_, value)
        return jsonify(_make_response(client_tx_id=tid))
    except (ValueError, PermissionError) as e:
        return jsonify(_make_response(
            error_number=ERROR_INVALID_VALUE, error_message=str(e), client_tx_id=tid,
        )), 400


# ---------------------------------------------------------------------------
# Management endpoints
# ---------------------------------------------------------------------------

@app.route('/management/apiversions', methods=['GET'])
def get_apiversions():
    return jsonify({
        "Value": [1],
        "ClientTransactionID": 0,
        "ServerTransactionID": _next_tx(),
        "ErrorNumber": 0,
        "ErrorMessage": "",
    })


@app.route('/management/v1/description', methods=['GET'])
def get_management_description():
    value = {
        "ServerName":          safety_monitor.alpaca_config.device_name,
        "Manufacturer":        "PsY4",
        "ManufacturerVersion": safety_monitor.alpaca_config.driver_version,
        "Location":            safety_monitor.alpaca_config.location,
    }
    host_override = os.getenv("ALPACA_HOST_OVERRIDE")
    if host_override:
        value["Server"] = host_override
    return jsonify(_make_response(value=value))


@app.route('/management/v1/configureddevices', methods=['GET'])
def get_configured_devices():
    logger.info("Get list of configured devices...")
    value = [
        {
            "DeviceName":   safety_monitor.alpaca_config.device_name,
            "DeviceType":   "SafetyMonitor",
            "DeviceNumber": safety_monitor.alpaca_config.device_number,
            "UniqueID":     "astropsy-safety-monitor-0",
        },
        {
            "DeviceName":   "AstroPsy Switch",
            "DeviceType":   "Switch",
            "DeviceNumber": AlpacaSwitch.DEVICE_NUMBER,
            "UniqueID":     "astropsy-switch-0",
        },
    ]
    return jsonify(_make_response(value=value))


# ---------------------------------------------------------------------------
# Setup pages — redirect vers Symfony
# ---------------------------------------------------------------------------

def _symfony_url(path: str) -> str:
    """Builds the Symfony app URL for a given path.
    Uses SETUP_BASE_URL env var if set, otherwise derives it from the incoming
    request host (strips the Alpaca port, assumes Symfony is on port 80).
    """
    base = os.getenv('SETUP_BASE_URL', '').rstrip('/')
    if not base:
        host = request.host.split(':')[0]
        base = f'http://{host}'
    return base + path


@app.route('/setup/v1/safetymonitor/<int:device_number>/setup', methods=['GET', 'POST'])
def setup_device(device_number: int):
    return redirect(_symfony_url(f'/alpaca/setup/safetymonitor/{device_number}'), 302)


@app.route('/setup/v1/switch/<int:device_number>/setup', methods=['GET', 'POST'])
def setup_switch(device_number: int):
    return redirect(_symfony_url(f'/alpaca/setup/switch/{device_number}'), 302)


# ---------------------------------------------------------------------------
# Internal JSON API — consumed by the Symfony AlpacaClient
# ---------------------------------------------------------------------------

@app.route('/internal/safetymonitor/<int:device_number>/config', methods=['GET'])
def internal_sm_get_config(device_number: int):
    if device_number != safety_monitor.alpaca_config.device_number:
        return jsonify({"error": "Invalid device number"}), 404
    cfg = safety_monitor.alpaca_config
    with safety_monitor._weather_lock:
        condition   = safety_monitor._current_condition
        is_safe     = safety_monitor._is_safe_weather
        last_upd    = safety_monitor._last_updated
    return jsonify({
        "config": {
            "device_name":       cfg.device_name,
            "location":          cfg.location,
            "latitude":          cfg.latitude,
            "longitude":         cfg.longitude,
            "elevation":         cfg.elevation,
            "timezone":          cfg.timezone,
            "forecast_url":      cfg.forecast_url,
            "update_interval":   cfg.update_interval,
            "unsafe_conditions": cfg.unsafe_conditions,
            "all_conditions":    ALL_CLOUD_CONDITIONS,
        },
        "weather": {
            "condition":    condition,
            "is_safe":      is_safe,
            "last_updated": last_upd.isoformat() if last_upd else None,
        },
    })


@app.route('/internal/safetymonitor/<int:device_number>/config', methods=['PUT'])
def internal_sm_save_config(device_number: int):
    if device_number != safety_monitor.alpaca_config.device_number:
        return jsonify({"error": "Invalid device number"}), 404
    data = request.get_json(force=True, silent=True) or {}
    cfg  = safety_monitor.alpaca_config

    if data.get('device_name', '').strip():
        cfg.device_name = data['device_name'].strip()
    if data.get('location', '').strip():
        cfg.location = data['location'].strip()
    try:
        if 'latitude'  in data: cfg.latitude  = float(data['latitude'])
        if 'longitude' in data: cfg.longitude = float(data['longitude'])
        if 'elevation' in data: cfg.elevation = int(data['elevation'])
    except (ValueError, TypeError):
        pass
    if data.get('timezone', '').strip():
        cfg.timezone = data['timezone'].strip()
    if data.get('forecast_url', '').strip():
        cfg.forecast_url = data['forecast_url'].strip()
    if isinstance(data.get('unsafe_conditions'), list):
        cfg.unsafe_conditions = [
            c for c in data['unsafe_conditions'] if c in ALL_CLOUD_CONDITIONS
        ]

    cfg.save_to_file()
    threading.Thread(target=safety_monitor._fetch_and_evaluate, daemon=True).start()
    return jsonify({"ok": True})


@app.route('/internal/switch/<int:device_number>/config', methods=['GET'])
def internal_sw_get_config(device_number: int):
    if device_number != AlpacaSwitch.DEVICE_NUMBER:
        return jsonify({"error": "Invalid device number"}), 404
    items = switch_device.items_snapshot()
    return jsonify({
        "items": [
            {
                "id":          item.id,
                "name":        item.name,
                "description": item.description,
                "is_boolean":  item.is_boolean,
                "value":       item.value,
                "min_value":   item.min_value,
                "max_value":   item.max_value,
                "step":        item.step,
                "can_write":   item.can_write,
            }
            for item in items
        ],
    })


@app.route('/internal/switch/<int:device_number>/config', methods=['PUT'])
def internal_sw_save_config(device_number: int):
    if device_number != AlpacaSwitch.DEVICE_NUMBER:
        return jsonify({"error": "Invalid device number"}), 404
    data   = request.get_json(force=True, silent=True) or {}
    action = data.get('action', 'save')

    if action == 'add':
        name       = (data.get('name') or 'New Item').strip()
        desc       = (data.get('description') or '').strip()
        is_boolean = bool(data.get('is_boolean', True))
        try:
            min_val = float(data.get('min_value', 0.0))
            max_val = float(data.get('max_value', 1.0))
            step    = float(data.get('step', 1.0))
        except (ValueError, TypeError):
            min_val, max_val, step = 0.0, 1.0, 1.0
        if is_boolean:
            min_val, max_val, step = 0.0, 1.0, 1.0
        switch_device.add_item(name, desc, is_boolean, min_val, max_val, step)
        switch_device.save_to_file()
        return jsonify({"ok": True, "count": switch_device.max_switch()})

    if action == 'delete':
        ids = sorted([int(i) for i in data.get('ids', [])], reverse=True)
        for id_ in ids:
            switch_device.remove_item(id_)
        switch_device.save_to_file()
        return jsonify({"ok": True, "count": switch_device.max_switch()})

    # action == 'save'
    snapshot = switch_device.items_snapshot()
    updates  = {int(u['id']): u for u in data.get('items', []) if 'id' in u}
    for item in snapshot:
        if item.id not in updates:
            continue
        u = updates[item.id]
        name = (u.get('name') or item.name).strip()
        desc = (u.get('description', item.description) or '').strip()
        try:
            value = float(u['value']) if 'value' in u else item.value
        except (ValueError, TypeError):
            value = item.value
        min_val = item.min_value
        max_val = item.max_value
        step    = item.step
        if not item.is_boolean:
            try: min_val = float(u.get('min_value', min_val))
            except (ValueError, TypeError): pass
            try: max_val = float(u.get('max_value', max_val))
            except (ValueError, TypeError): pass
            try: step    = float(u.get('step', step))
            except (ValueError, TypeError): pass
        switch_device.update_item(
            item.id, name=name, description=desc, value=value,
            min_value=min_val, max_value=max_val, step=step,
        )
    switch_device.save_to_file()
    return jsonify({"ok": True, "count": switch_device.max_switch()})


# ---------------------------------------------------------------------------
# UDP Discovery
# ---------------------------------------------------------------------------

class AlpacaDiscovery:
    DISCOVERY_PORT    = 32227
    DISCOVERY_MESSAGE = b"alpacadiscovery1"

    def __init__(self, alpaca_port: int):
        self.alpaca_port = alpaca_port
        self.socket  = None
        self.running = False
        self.thread  = None

    def start(self):
        try:
            self.socket = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            self.socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            self.socket.bind(('', self.DISCOVERY_PORT))
            self.running = True
            self.thread = threading.Thread(target=self._discovery_loop, daemon=True)
            self.thread.start()
            logger.info(f"Alpaca Discovery started on UDP {self.DISCOVERY_PORT}")
        except Exception as e:
            logger.error(f"Failed to start discovery: {e}")

    def stop(self):
        self.running = False
        if self.socket:
            self.socket.close()
        if self.thread:
            self.thread.join(timeout=2)

    def _discovery_loop(self):
        while self.running:
            try:
                data, addr = self.socket.recvfrom(1024)
                if data == self.DISCOVERY_MESSAGE:
                    logger.info(f"Discovery request from {addr[0]}:{addr[1]}")
                    self._send_discovery_response(addr)
            except Exception as e:
                if self.running:
                    logger.error(f"Error in discovery loop: {e}")

    def _send_discovery_response(self, addr):
        try:
            response = json.dumps({"AlpacaPort": self.alpaca_port}).encode('utf-8')
            self.socket.sendto(response, addr)
            logger.info(f"Sent discovery response — port {self.alpaca_port}")
        except Exception as e:
            logger.error(f"Failed to send discovery response: {e}")


# ---------------------------------------------------------------------------
# Application factory
# ---------------------------------------------------------------------------

def create_app():
    global safety_monitor, switch_device, discovery_service

    alpaca_config = AlpacaConfig.load_from_file() or AlpacaConfig()

    # Environment variables override config file
    alpaca_config.port            = int(os.getenv('ALPACA_PORT',            str(alpaca_config.port)))
    alpaca_config.device_number   = int(os.getenv('ALPACA_DEVICE_NUMBER',   str(alpaca_config.device_number)))
    alpaca_config.update_interval = int(os.getenv('ALPACA_UPDATE_INTERVAL', str(alpaca_config.update_interval)))
    alpaca_config.latitude        = float(os.getenv('ALPACA_LATITUDE',      str(alpaca_config.latitude)))
    alpaca_config.longitude       = float(os.getenv('ALPACA_LONGITUDE',     str(alpaca_config.longitude)))
    alpaca_config.elevation       = int(os.getenv('ALPACA_ELEVATION',       str(alpaca_config.elevation)))
    alpaca_config.timezone        = os.getenv('ALPACA_TIMEZONE',             alpaca_config.timezone)
    alpaca_config.forecast_url    = os.getenv('ALPACA_FORECAST_URL',         alpaca_config.forecast_url)

    alpaca_config.save_to_file()

    safety_monitor = AlpacaSafetyMonitor(alpaca_config)
    safety_monitor.start_weather_updates()

    switch_device = AlpacaSwitch()
    switch_device.load_from_file()

    discovery_service = AlpacaDiscovery(alpaca_config.port)
    discovery_service.start()

    logger.info(f"Device       : {alpaca_config.device_name}")
    logger.info(f"Coordinates  : {alpaca_config.latitude}, {alpaca_config.longitude} @ {alpaca_config.elevation}m")
    logger.info(f"Forecast URL : {alpaca_config.forecast_url}")
    logger.info(f"Update every : {alpaca_config.update_interval}s")
    logger.info(f"Unsafe conds : {', '.join(alpaca_config.unsafe_conditions)}")

    return app


def main():
    application = create_app()
    port = int(os.getenv('ALPACA_PORT', '11111'))
    logger.info(f"Starting ASCOM Alpaca server on port {port}")
    application.run(host='0.0.0.0', port=port, debug=False)


if __name__ == '__main__':
    main()
else:
    app = create_app()
