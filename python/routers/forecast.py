from typing import Optional

from fastapi import APIRouter, Query

from services.forecast_service import (
    _cache_key, _cache_get, _cache_set,
    fetch_astro_timeseries,
)

router = APIRouter()


@router.get("/astro/forecast")
async def astro_forecast(
    lat: float = Query(..., description="Latitude in decimal degrees"),
    lon: float = Query(..., description="Longitude in decimal degrees"),
    elevation: Optional[int] = Query(None, description="Elevation in meters"),
    tz: str = Query("Europe/Paris", description="IANA timezone for sun/moon & local times"),
    forecast_model: str = Query("icon_seamless", description="pyastroweatherio forecast model"),
    cloudcover_weight: float = Query(1.0),
    cloudcover_high_weakening: float = Query(0.5),
    cloudcover_medium_weakening: float = Query(0.7),
    cloudcover_low_weakening: float = Query(1.0),
    fog_weight: float = Query(1.0),
    seeing_weight: float = Query(1.0),
    transparency_weight: float = Query(1.0),
    calm_weight: float = Query(1.0),
    use_openmeteo: bool = Query(True, description="Enable Open-Meteo (if supported by your pyastroweatherio build)"),
    experimental_features: bool = Query(False),
    cache: bool = Query(True, description="Use 10-minute in-memory cache"),
):
    key = _cache_key(
        lat=round(lat, 4), lon=round(lon, 4), elevation=elevation or 0,
        tz=tz, fm=forecast_model,
        ccw=cloudcover_weight, chw=cloudcover_high_weakening,
        cmw=cloudcover_medium_weakening, clw=cloudcover_low_weakening,
        fw=fog_weight, sw=seeing_weight, tw=transparency_weight,
        cw=calm_weight, em=experimental_features, om=use_openmeteo,
    )
    if cache:
        hit = _cache_get(key)
        if hit is not None:
            return hit

    data = await fetch_astro_timeseries(
        latitude=lat,
        longitude=lon,
        elevation=elevation,
        tz=tz,
        forecast_model=forecast_model,
        cloudcover_weight=cloudcover_weight,
        cloudcover_high_weakening=cloudcover_high_weakening,
        cloudcover_medium_weakening=cloudcover_medium_weakening,
        cloudcover_low_weakening=cloudcover_low_weakening,
        fog_weight=fog_weight,
        seeing_weight=seeing_weight,
        transparency_weight=transparency_weight,
        calm_weight=calm_weight,
        use_openmeteo=use_openmeteo,
        experimental_features=experimental_features,
    )

    if cache:
        _cache_set(key, data)
    return data
