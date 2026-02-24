import asyncio
from dataclasses import is_dataclass, asdict
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

import aiohttp
from pyastroweatherio import AstroWeather

# ---------------------------------------------------------------------------
# TTL cache (in-memory, 10-minute TTL)
# ---------------------------------------------------------------------------

_CACHE: Dict[str, Dict[str, Any]] = {}
TTL_SECONDS = 10 * 60


def _cache_key(**kwargs) -> str:
    return "|".join(f"{k}={kwargs[k]}" for k in sorted(kwargs))


def _cache_get(key: str):
    entry = _CACHE.get(key)
    if not entry:
        return None
    if (datetime.now(timezone.utc).timestamp() - entry["ts"]) > TTL_SECONDS:
        _CACHE.pop(key, None)
        return None
    return entry["data"]


def _cache_set(key: str, data: Any):
    _CACHE[key] = {"ts": datetime.now(timezone.utc).timestamp(), "data": data}


# ---------------------------------------------------------------------------
# Helpers — field lookup in dicts / dataclasses / objects
# ---------------------------------------------------------------------------

NESTED_KEYS = ("condition_data", "time_data", "moon_data", "sun_data")


def _as_mapping(x):
    return asdict(x) if is_dataclass(x) else x


def _lookup(obj, name: str):
    """
    Get a single field by name from obj (dict / dataclass / object),
    also searching in known nested containers.
    """
    o = _as_mapping(obj)

    if isinstance(o, dict):
        if name in o and o[name] is not None:
            return o[name]
        for nk in NESTED_KEYS:
            sub = o.get(nk)
            if isinstance(sub, dict) and name in sub and sub[name] is not None:
                return sub[name]
        return None

    if hasattr(o, name):
        v = getattr(o, name)
        if v is not None:
            return v
    for nk in NESTED_KEYS:
        if hasattr(o, nk):
            sub = getattr(o, nk)
            if sub is None:
                continue
            if isinstance(sub, dict) and name in sub and sub[name] is not None:
                return sub[name]
            if hasattr(sub, name):
                v = getattr(sub, name)
                if v is not None:
                    return v
    return None


def _field(obj, *names, default=None):
    """Return the first non-None value found among candidate field names."""
    for n in names:
        v = _lookup(obj, n)
        if v is not None:
            return v
    return default


async def _safe_call(obj, name: str):
    """Call obj.name() whether it's sync or async, swallowing exceptions."""
    fn = getattr(obj, name, None)
    if not fn:
        return None
    try:
        if asyncio.iscoroutinefunction(fn):
            return await fn()
        res = fn()
        if asyncio.iscoroutine(res):
            return await res
        return res
    except Exception:
        return None


def _first_list_with_many(x) -> Optional[List[Any]]:
    return x if isinstance(x, list) and len(x) > 1 else None


# ---------------------------------------------------------------------------
# Normalization helpers
# ---------------------------------------------------------------------------

def _iso(ts: datetime) -> str:
    if ts.tzinfo is None:
        ts = ts.replace(tzinfo=timezone.utc)
    return ts.astimezone(timezone.utc).isoformat()


def _pct(x):
    if x is None:
        return None
    return x * 100.0 if isinstance(x, (int, float)) and 0 <= x <= 1 else x


def _drop_nulls(d: dict) -> dict:
    return {k: v for k, v in d.items() if v is not None}


def _to_series(items: List[Any]) -> List[dict]:
    out = []
    for p in items:
        t = _field(p, "time_iso", "time", "datetime_iso", "datetime", "forecast_time")
        if isinstance(t, datetime):
            t = _iso(t)

        row = {
            "t": t,
            "cloud_total":          _field(p, "cloudcover_total", "clouds_total", "cloudcover", "cloud_area_fraction"),
            "cloud_high":           _field(p, "cloudcover_high", "cloud_area_fraction_high"),
            "cloud_mid":            _field(p, "cloudcover_medium", "cloud_area_fraction_medium", "cloud_area_fraction_mid"),
            "cloud_low":            _field(p, "cloudcover_low", "cloud_area_fraction_low"),
            "fog":                  _pct(_field(p, "fog", "fog_area_fraction", "fog2m")),
            "seeing_arcsec":        _field(p, "seeing", "_seeing"),
            "transparency_index":   _field(p, "transparency", "_transparency"),
            "wind_speed":           _field(p, "wind_speed", "windspeed", "wind10m"),
            "wind_gust":            _field(p, "wind_gust", "gust", "windgust", "wind_gusts_10m"),
            "wind_dir":             _field(p, "wind_direction", "wind_from_direction", "winddirection"),
            "humidity":             _field(p, "humidity", "rh2m"),
            "temperature":          _field(p, "temperature", "temp2m"),
            "dewpoint":             _field(p, "dewpoint", "dewpoint2m", "_dewpoint2m"),
            "pressure":             _field(p, "pressure", "msl_pressure", "pressure_msl", "surface_pressure"),
            "precip_mm":            _field(p, "precipitation", "precipitation_amount", "precipitation_amount6"),
            "condition_score":      _field(p, "condition_score", "condition_percentage"),
            "astronomical_twilight": _field(p, "astronomical_twilight"),
            "nautical_twilight":    _field(p, "nautical_twilight"),
            "civil_twilight":       _field(p, "civil_twilight"),
            "moon_phase":           _field(p, "moon_phase", "phase"),
            "moon_alt":             _field(p, "moon_alt", "altitude"),
            "moon_illumination":    _field(p, "moon_illumination"),
        }
        out.append(_drop_nulls(row))
    return out


# ---------------------------------------------------------------------------
# Main forecast fetch
# ---------------------------------------------------------------------------

async def fetch_astro_timeseries(
    latitude: float,
    longitude: float,
    elevation: Optional[int],
    tz: str,
    forecast_model: str,
    cloudcover_weight: float,
    cloudcover_high_weakening: float,
    cloudcover_medium_weakening: float,
    cloudcover_low_weakening: float,
    fog_weight: float,
    seeing_weight: float,
    transparency_weight: float,
    calm_weight: float,
    use_openmeteo: bool = False,
    experimental_features: bool = False,
) -> Dict[str, Any]:

    async with aiohttp.ClientSession() as session:
        aw = AstroWeather(
            session=session,
            latitude=float(latitude),
            longitude=float(longitude),
            elevation=int(elevation or 0),
            timezone_info=tz,
            cloudcover_weight=cloudcover_weight,
            cloudcover_high_weakening=cloudcover_high_weakening,
            cloudcover_medium_weakening=cloudcover_medium_weakening,
            cloudcover_low_weakening=cloudcover_low_weakening,
            fog_weight=fog_weight,
            seeing_weight=seeing_weight,
            transparency_weight=transparency_weight,
            calm_weight=calm_weight,
            uptonight_path="",
            experimental_features=experimental_features,
            forecast_model=forecast_model,
        )

        loc = await _safe_call(aw, "get_location_data")

        candidates = ["get_hourly_forecast", "hourly_forecast", "get_forecast", "get_forecast_hours"]
        hours: Optional[List[Any]] = None

        for name in candidates:
            res = await _safe_call(aw, name)
            if _first_list_with_many(res):
                hours = res
                break
            if isinstance(res, dict):
                for k in ("hourly", "hours", "forecast", "data"):
                    if _first_list_with_many(res.get(k)):
                        hours = res[k]
                        break
            if isinstance(res, list) and len(res) == 1 and isinstance(res[0], dict):
                for k in ("hourly", "hours", "forecast", "data"):
                    if _first_list_with_many(res[0].get(k)):
                        hours = res[0][k]
                        break
            if hours:
                break

        if not hours:
            src = loc if isinstance(loc, (dict, list)) else None
            if isinstance(src, dict):
                for k in ("hourly", "hours", "forecast", "data"):
                    if _first_list_with_many(src.get(k)):
                        hours = src[k]
                        break
            elif isinstance(src, list) and len(src) > 1:
                hours = src

        if not hours:
            hours = await _safe_call(aw, "get_hourly_forecast") or []

        series = _to_series(hours)
        meta = {
            "provider": "pyastroweatherio",
            "model": forecast_model,
            "latitude": latitude,
            "longitude": longitude,
            "elevation": elevation or 0,
            "timezone": tz,
            "generated_at": _iso(datetime.utcnow().replace(tzinfo=timezone.utc)),
            "notes": (
                "Fields are optional; null if not provided by the upstream model. "
                f"hour_count={len(hours)}"
            ),
        }
        return {"meta": meta, "series": series, "raw": {"location": loc, "hourly": hours}}
