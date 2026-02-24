import io
from datetime import datetime, timezone
from fractions import Fraction

import exifread
import numpy as np
import rawpy
from fastapi import APIRouter, HTTPException
from fastapi.responses import JSONResponse, Response
from PIL import Image, ImageOps

from services.stretch import resize_keep_ratio, stretch_channel
from utils.path_guard import require_safe_path

router = APIRouter()


@router.get("/nef/render")
def nef_render(path: str, w: int = 1920, stretch: str = "asinh", bp: float = 0.1, wp: float = 99.9):
    p = require_safe_path(path)
    try:
        with rawpy.imread(str(p)) as raw:
            rgb = raw.postprocess(
                output_bps=8, use_camera_wb=True, no_auto_bright=True,
                gamma=(1, 1),
                half_size=True, demosaic_algorithm=rawpy.DemosaicAlgorithm.AHD,
            )
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to decode NEF: {str(e)}")
    r = stretch_channel(rgb[:, :, 0].astype(np.float32), stretch, bp, wp)
    g = stretch_channel(rgb[:, :, 1].astype(np.float32), stretch, bp, wp)
    b = stretch_channel(rgb[:, :, 2].astype(np.float32), stretch, bp, wp)
    im = resize_keep_ratio(Image.fromarray(np.stack([r, g, b], axis=2)), w)
    buf = io.BytesIO()
    im.save(buf, format="PNG")
    buf.seek(0)
    return Response(content=buf.getvalue(), media_type="image/png")


@router.get("/nef/histogram")
def nef_histogram(path: str):
    p = require_safe_path(path)
    try:
        with rawpy.imread(str(p)) as raw:
            rgb = raw.postprocess(
                output_bps=8, use_camera_wb=True, no_auto_bright=True,
                gamma=(1, 1), half_size=True,
                demosaic_algorithm=rawpy.DemosaicAlgorithm.AHD,
            )
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to decode NEF: {str(e)}")

    def hist(ch):
        counts, _ = np.histogram(ch.flatten(), bins=256, range=(0, 255))
        return counts.tolist()

    return JSONResponse({"r": hist(rgb[:, :, 0]), "g": hist(rgb[:, :, 1]), "b": hist(rgb[:, :, 2])})


@router.get("/nef/header")
def nef_header(path: str):
    p = require_safe_path(path)
    try:
        with open(str(p), "rb") as f:
            tags = exifread.process_file(f, details=False)

        def get(tag, default=None):
            v = tags.get(tag)
            return str(v) if v is not None else default

        date_raw = get("EXIF DateTimeOriginal") or get("Image DateTime")
        date_iso = None
        if date_raw:
            try:
                dt = datetime.strptime(date_raw, "%Y:%m:%d %H:%M:%S")
                date_iso = dt.replace(tzinfo=timezone.utc).isoformat()
            except Exception:
                date_iso = None

        exp_raw = get("EXIF ExposureTime")
        exp_s = None
        if exp_raw:
            try:
                exp_s = float(Fraction(exp_raw))
            except Exception:
                exp_s = None

        payload = {
            "FORMAT":   "NEF",
            "IMAGETYP": None,
            "DATE-OBS": date_iso,
            "EXPOSURE": exp_s,
            "FILTER":   None,
            "CCD-TEMP": None,
            "ISO":      get("EXIF ISOSpeedRatings"),
            "CAMERA":   get("Image Model"),
            "LENS":     get("EXIF LensModel") or get("EXIF LensSpecification"),
            "EXIF":     {k: str(v) for k, v in tags.items()},
        }
        return JSONResponse(payload)

    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Failed to read NEF EXIF: {str(e)}")


@router.get("/nef/thumbnail")
def nef_thumbnail(path: str, w: int = 512):
    p = require_safe_path(path)
    try:
        with rawpy.imread(str(p)) as raw:
            try:
                thumb = raw.extract_thumb()
                if thumb.format == rawpy.ThumbFormat.JPEG:
                    im = Image.open(io.BytesIO(thumb.data))
                    im = ImageOps.exif_transpose(im)
                elif thumb.format == rawpy.ThumbFormat.BITMAP:
                    im = Image.fromarray(thumb.data)
                else:
                    raise rawpy.LibRawUnsupportedThumbnailError("Unknown thumbnail format")
            except (rawpy.LibRawNoThumbnailError, rawpy.LibRawUnsupportedThumbnailError):
                rgb = raw.postprocess(
                    output_bps=8,
                    use_camera_wb=True,
                    no_auto_bright=True,
                    gamma=(1, 1),
                    half_size=True,
                    demosaic_algorithm=rawpy.DemosaicAlgorithm.AHD,
                )
                im = Image.fromarray(rgb)
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to decode NEF: {str(e)}")

    try:
        if im.width == 0:
            raise ValueError("Invalid image width")
        im = resize_keep_ratio(im, w)
        if im.mode != "RGB":
            im = im.convert("RGB")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to resize/convert: {str(e)}")

    buf = io.BytesIO()
    im.save(buf, format="PNG")
    buf.seek(0)
    return Response(content=buf.getvalue(), media_type="image/png")
