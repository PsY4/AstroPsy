import numpy as np
from fastapi import HTTPException

from utils.json_utils import _json_safe, _to_float


def _read_xisf_array(path: str) -> np.ndarray:
    """
    Read an XISF file into a float64 numpy array (H×W mono or H×W×3 RGB).
    Lazily imports the xisf package.
    """
    try:
        from xisf import XISF
    except ImportError as e:
        raise HTTPException(
            status_code=501,
            detail="XISF support not installed. pip install xisf",
        ) from e

    try:
        data = XISF.read(path)
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"XISF read error: {e}")

    arr = np.asarray(data)
    if arr.ndim > 3:
        arr = arr[0]
    if arr.ndim == 3 and arr.shape[2] not in (1, 3):
        arr = arr[..., :3] if arr.shape[2] >= 3 else arr[..., :1]

    return arr.astype(np.float64, copy=False)


def _read_xisf(path: str):
    """
    Read an XISF file using the class-based API.
    Returns (array, images_metadata[0], file_metadata).
    """
    try:
        from xisf import XISF
    except ImportError:
        raise HTTPException(status_code=500, detail="xisf package not installed")

    try:
        xf = XISF(path)
        file_meta = xf.get_file_metadata()
        images_meta = xf.get_images_metadata()
        arr = xf.read_image(0)
        return arr, images_meta[0], file_meta
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"XISF read error: {e}")


def _flatten_metadata(img_meta: dict, file_meta: dict) -> dict:
    out = {}

    if "dtype" in img_meta:
        out["dtype"] = str(img_meta["dtype"])
    if "geometry" in img_meta:
        out["geometry"] = _json_safe(img_meta["geometry"])
    if "compression" in img_meta:
        out["compression"] = _json_safe(img_meta["compression"])

    fits_kw = {}
    for key, items in img_meta.get("FITSKeywords", {}).items():
        if items:
            fits_kw[key] = _json_safe(items[-1].get("value"))
    if fits_kw:
        out["FITS"] = fits_kw

    props = {}
    for pid, pinfo in img_meta.get("XISFProperties", {}).items():
        props[pid] = _json_safe(pinfo.get("value"))
    if props:
        out["XISFProperties"] = props

    file_props = {}
    for pid, pinfo in file_meta.items():
        file_props[pid] = _json_safe(pinfo.get("value"))
    if file_props:
        out["XISFFile"] = file_props

    def first_non_none(*vals):
        for v in vals:
            if v is not None:
                return v
        return None

    out["aliases"] = {
        "object":        first_non_none(fits_kw.get("OBJECT"), props.get("Observation:TargetName")),
        "exposure_s":    first_non_none(
            _to_float(fits_kw.get("EXPTIME")),
            _to_float(props.get("Instrument:ExposureTime")),
        ),
        "filter":        first_non_none(fits_kw.get("FILTER"), props.get("Instrument:Filter")),
        "gain":          first_non_none(_to_float(fits_kw.get("GAIN")), _to_float(props.get("Instrument:Gain"))),
        "offset":        first_non_none(_to_float(fits_kw.get("OFFSET")), _to_float(props.get("Instrument:Offset"))),
        "sensor_temp_c": first_non_none(
            _to_float(fits_kw.get("CCD-TEMP")),
            _to_float(props.get("Instrument:SensorTemperature")),
        ),
        "date_obs":      first_non_none(fits_kw.get("DATE-OBS"), props.get("Observation:DateObs")),
        "e_gain":        first_non_none(_to_float(fits_kw.get("EGAIN")), _to_float(props.get("Instrument:eGain"))),
    }

    return out
