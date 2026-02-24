import io
import numpy as np
from astropy.io import fits
from fastapi import APIRouter, Query
from fastapi.responses import JSONResponse, Response

from services.fits_service import first_image_hdu, to_js9_safe_hdu
from services.stretch import _percentile_asinh_8bit, resize_keep_ratio
from utils.path_guard import require_safe_path
from PIL import Image

router = APIRouter()


@router.get("/fits/header")
def fits_header(path: str):
    p = require_safe_path(path)
    try:
        with fits.open(str(p), memmap=False, ignore_missing_end=True) as hdul:
            hdu = first_image_hdu(hdul) or hdul[0]
            hdr = {k: (str(v) if not isinstance(v, (int, float, str)) else v)
                   for k, v in hdu.header.items()}
        return JSONResponse(hdr)
    except Exception as e:
        from fastapi import HTTPException
        raise HTTPException(status_code=400, detail=str(e))


@router.get("/fits/thumbnail")
def fits_thumbnail(path: str, w: int = 512):
    p = require_safe_path(path)
    try:
        with fits.open(str(p), memmap=False, ignore_missing_end=True) as hdul:
            hdu = first_image_hdu(hdul)
            if hdu is None or hdu.data is None:
                from fastapi import HTTPException
                raise HTTPException(status_code=400, detail="No image data")
            data = np.asarray(hdu.data, dtype=np.float64)
            if data.ndim > 2:
                data = data[0]
            img8 = _percentile_asinh_8bit(data)
            im = resize_keep_ratio(Image.fromarray(img8), w)
            buf = io.BytesIO()
            im.save(buf, format="PNG")
            return Response(content=buf.getvalue(), media_type="image/png")
    except Exception as e:
        from fastapi import HTTPException
        raise HTTPException(status_code=400, detail=str(e))


@router.get("/js9safe")
def js9safe(path: str = Query(..., description="Path to FITS")):
    p = require_safe_path(path)
    try:
        with fits.open(str(p), memmap=False, ignore_missing_end=True) as hdul:
            hdu = first_image_hdu(hdul)
            if hdu is None or hdu.data is None:
                from fastapi import HTTPException
                raise HTTPException(status_code=400, detail="No image data in FITS")
            safe = to_js9_safe_hdu(hdu)
            buf = io.BytesIO()
            fits.HDUList([safe]).writeto(buf, overwrite=True, output_verify="silentfix")
            return Response(content=buf.getvalue(), media_type="application/octet-stream")
    except Exception as e:
        from fastapi import HTTPException
        raise HTTPException(status_code=400, detail=str(e))
