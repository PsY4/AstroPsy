import io

from fastapi import APIRouter, HTTPException
from fastapi.responses import JSONResponse, Response
from PIL import Image

from services.xisf_service import _read_xisf_array, _read_xisf, _flatten_metadata
from services.stretch import to_rgb_image, resize_keep_ratio
from utils.json_utils import _json_safe
from utils.path_guard import require_safe_path

router = APIRouter()


@router.get("/xisf/thumbnail")
def xisf_thumbnail(path: str, w: int = 512):
    p = require_safe_path(path)
    if not str(p).lower().endswith(".xisf"):
        raise HTTPException(status_code=415, detail="Unsupported format (expecting .xisf)")

    arr = _read_xisf_array(str(p))
    im = to_rgb_image(arr)
    im = resize_keep_ratio(im, w)

    buf = io.BytesIO()
    im.save(buf, format="PNG")
    buf.seek(0)
    return Response(content=buf.getvalue(), media_type="image/png")


@router.get("/xisf/header")
def xisf_header(path: str):
    p = require_safe_path(path)
    if not str(p).lower().endswith(".xisf"):
        raise HTTPException(status_code=415, detail="Unsupported format (expecting .xisf)")

    arr, img_meta, file_meta = _read_xisf(str(p))

    channels = 1 if arr.ndim == 2 else (arr.shape[2] if arr.ndim == 3 else 1)
    info = {
        "path": path,
        "shape": list(arr.shape),
        "dtype": str(arr.dtype),
        "channels": int(channels),
        "kind": "mono" if channels == 1 else "rgb_like",
        "metadata": _flatten_metadata(img_meta, file_meta),
    }
    return JSONResponse(_json_safe(info))
