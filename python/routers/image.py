import io
import os
import traceback
from datetime import datetime

import tifffile as tiff
from fastapi import APIRouter, HTTPException
from fastapi.responses import JSONResponse, Response
from PIL import Image, ExifTags, TiffImagePlugin, TiffTags, ImageFile

from services.stretch import to_rgb_image, resize_keep_ratio
from services.tiff_service import open_tiff_as_image, _sniff_tiff_magic
from utils.path_guard import require_safe_path

ImageFile.LOAD_TRUNCATED_IMAGES = True
Image.MAX_IMAGE_PIXELS = None

from fractions import Fraction
from numbers import Number

router = APIRouter()

ALLOWED_IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".tif", ".tiff"}


def _sanitize_tag_value(value):
    """Make a TIFF tag value JSON-serializable."""
    if isinstance(value, (int, float, bool, str)):
        return value
    if isinstance(value, (bytes, bytearray)):
        if len(value) > 256:
            return f"<{len(value)} bytes>"
        try:
            return value.decode(errors="replace")
        except Exception:
            return f"<{len(value)} bytes>"
    if isinstance(value, Fraction):
        return float(value)
    if hasattr(value, 'numerator') and hasattr(value, 'denominator'):
        # IFDRational and similar fraction-like objects
        try:
            return float(value)
        except (ValueError, ZeroDivisionError):
            return str(value)
    if isinstance(value, (tuple, list)):
        return [_sanitize_tag_value(v) for v in value]
    if isinstance(value, dict):
        return {str(k): _sanitize_tag_value(v) for k, v in value.items()}
    if isinstance(value, Number):
        return float(value)
    return str(value)

# ── Human-readable TIFF enums ──────────────────────────────────────────────
_COMPRESSION_NAMES = {
    1: "Uncompressed", 2: "CCITT RLE", 3: "CCITT Group 3", 4: "CCITT Group 4",
    5: "LZW", 6: "JPEG (old)", 7: "JPEG", 8: "Deflate (ZIP)",
    32773: "PackBits", 34712: "JPEG 2000", 50000: "ZSTD", 50001: "WebP",
}
_SAMPLE_FORMAT_NAMES = {1: "uint", 2: "int", 3: "float", 4: "void"}
_PHOTOMETRIC_NAMES = {
    0: "MinIsWhite", 1: "MinIsBlack", 2: "RGB", 3: "Palette",
    4: "Transparency Mask", 5: "CMYK", 6: "YCbCr", 8: "CIELab",
}
_PREDICTOR_NAMES = {1: "None", 2: "Horizontal differencing", 3: "Floating point"}

# Tags to exclude from the raw tiff_tags dump (noisy, no value for users)
_TIFF_TAGS_EXCLUDE = {
    "StripOffsets", "StripByteCounts", "TileOffsets", "TileByteCounts",
    "FreeOffsets", "FreeByteCounts", "ICCProfile", "IptcNaaInfo",
    "XMP", "ExifIFD", "InterColorProfile", "JPEGTables",
}


def _build_tiff_summary(tiff_tags: dict) -> dict:
    """Extract a human-readable summary from raw TIFF tags."""
    summary = {}

    bps = tiff_tags.get("BitsPerSample")
    if bps is not None:
        if isinstance(bps, (tuple, list)):
            summary["bit_depth"] = bps[0] if len(set(bps)) == 1 else "×".join(str(b) for b in bps)
        else:
            summary["bit_depth"] = bps

    spp = tiff_tags.get("SamplesPerPixel")
    if spp is not None:
        summary["samples_per_pixel"] = spp
        channel_names = {1: "Mono", 3: "RGB", 4: "RGBA"}
        summary["channels_label"] = channel_names.get(spp, f"{spp} channels")

    sf = tiff_tags.get("SampleFormat")
    if sf is not None:
        val = sf[0] if isinstance(sf, (tuple, list)) else sf
        summary["sample_format"] = _SAMPLE_FORMAT_NAMES.get(val, str(val))

    pi = tiff_tags.get("PhotometricInterpretation")
    if pi is not None:
        summary["photometric"] = _PHOTOMETRIC_NAMES.get(pi, str(pi))

    comp = tiff_tags.get("Compression")
    if comp is not None:
        summary["compression"] = _COMPRESSION_NAMES.get(comp, str(comp))

    pred = tiff_tags.get("Predictor")
    if pred is not None and pred != 1:
        summary["predictor"] = _PREDICTOR_NAMES.get(pred, str(pred))

    sw = tiff_tags.get("Software")
    if sw is not None:
        summary["software"] = str(sw).strip()

    dt = tiff_tags.get("DateTime")
    if dt is not None:
        summary["datetime"] = str(dt).strip()

    orient = tiff_tags.get("Orientation")
    if orient is not None:
        summary["orientation"] = orient

    return summary


@router.get("/image/thumbnail")
def image_thumbnail(path: str, w: int = 512):
    p = require_safe_path(path)
    ext = p.suffix.lower()
    if ext not in {".jpg", ".jpeg", ".png"}:
        raise HTTPException(status_code=415, detail="Unsupported format (expecting .jpg, .jpeg, or .png)")

    try:
        im = Image.open(str(p))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Failed to open image: {str(e)}")

    im = resize_keep_ratio(im, w)
    if im.mode != "RGB":
        im = im.convert("RGB")

    buf = io.BytesIO()
    im.save(buf, format="PNG")
    buf.seek(0)
    return Response(content=buf.getvalue(), media_type="image/png")


@router.get("/tif/thumbnail")
def tif_thumbnail(path: str, w: int = 512):
    p = require_safe_path(path)
    if p.suffix.lower() not in {".tif", ".tiff"}:
        raise HTTPException(status_code=415, detail="Unsupported format (expecting .tif/.tiff)")
    if w <= 0:
        raise HTTPException(status_code=400, detail="Width 'w' must be > 0")

    try:
        im = open_tiff_as_image(str(p))
        im = resize_keep_ratio(im, w)
        buf = io.BytesIO()
        im.save(buf, format="PNG")
        buf.seek(0)
        return Response(content=buf.getvalue(), media_type="image/png")
    except HTTPException:
        raise
    except Exception as e:
        print("[TIFF ERROR]", traceback.format_exc())
        raise HTTPException(status_code=500, detail=f"Unexpected TIFF error: {str(e)}")


@router.get("/image/header")
def image_header(path: str):
    p = require_safe_path(path)
    ext = p.suffix.lower()
    if ext not in ALLOWED_IMAGE_EXTS:
        raise HTTPException(415, "Unsupported format (jpg, jpeg, png, tif, tiff)")

    try:
        with Image.open(str(p)) as im:
            payload = {
                "path": path,
                "format": im.format,
                "mime": Image.MIME.get(im.format, "application/octet-stream"),
                "mode": im.mode,
                "width": im.width,
                "height": im.height,
                "size": f"{im.width}x{im.height}",
                "frames": getattr(im, "n_frames", 1),
                "animated": bool(getattr(im, "is_animated", False)),
                "has_palette": im.palette is not None,
            }

            info = {}
            if "dpi" in im.info:
                dpi = im.info["dpi"]
                if isinstance(dpi, (tuple, list)) and len(dpi) == 2:
                    info["dpi_x"], info["dpi_y"] = _sanitize_tag_value(dpi[0]), _sanitize_tag_value(dpi[1])
                else:
                    info["dpi"] = _sanitize_tag_value(dpi)
            if "icc_profile" in im.info:
                icc = im.info["icc_profile"]
                info["icc_profile"] = {"present": True, "bytes": len(icc) if isinstance(icc, (bytes, bytearray)) else 0}
            if "compression" in im.info:
                info["compression"] = im.info["compression"]
            if info:
                payload["info"] = info

            exif_map = {}
            try:
                exif = im.getexif()
                if exif:
                    for tag_id, value in exif.items():
                        tag = ExifTags.TAGS.get(tag_id, str(tag_id))
                        exif_map[tag] = _sanitize_tag_value(value)
            except Exception:
                pass
            if exif_map:
                payload["exif"] = exif_map

            if isinstance(im, TiffImagePlugin.TiffImageFile):
                try:
                    raw_tags = {}
                    for tag, value in im.tag_v2.items():
                        tag_info = TiffTags.TAGS_V2.get(tag)
                        tag_name = tag_info.name if tag_info else str(tag)
                        raw_tags[tag_name] = _sanitize_tag_value(value)
                    if raw_tags:
                        payload["tiff_summary"] = _build_tiff_summary(raw_tags)
                        payload["tiff_tags"] = {
                            k: v for k, v in raw_tags.items()
                            if k not in _TIFF_TAGS_EXCLUDE
                        }
                except Exception:
                    pass

        try:
            st = os.stat(str(p))
            payload["file"] = {
                "size_bytes": st.st_size,
                "modified": datetime.fromtimestamp(st.st_mtime).isoformat(),
                "created": datetime.fromtimestamp(st.st_ctime).isoformat(),
            }
        except Exception:
            pass

        return JSONResponse(payload)

    except Exception as e_pillow:
        if ext in {".tif", ".tiff"} or _sniff_tiff_magic(str(p)):
            try:
                with tiff.TiffFile(str(p)) as tf:
                    page = tf.pages[0]
                    payload = {
                        "path": path,
                        "format": "TIFF",
                        "mime": "image/tiff",
                        "mode": str(page.dtype),
                        "width": page.shape[-1],
                        "height": page.shape[-2],
                        "size": f"{page.shape[-1]}x{page.shape[-2]}",
                        "frames": len(tf.pages),
                        "animated": len(tf.pages) > 1,
                        "has_palette": bool(getattr(page, "colormap", None)),
                    }

                    res = {}
                    xres = page.tags.get("XResolution")
                    yres = page.tags.get("YResolution")
                    resunit = page.tags.get("ResolutionUnit")
                    if xres and yres:
                        try:
                            def _num(v):
                                return float(v[0] / v[1]) if hasattr(v, "__len__") and len(v) == 2 else float(v)
                            res["dpi_x"] = _num(xres.value)
                            res["dpi_y"] = _num(yres.value)
                        except Exception:
                            pass
                    if resunit:
                        res["resolution_unit"] = resunit.value
                    if res:
                        payload["resolution"] = res

                    raw_tags = {}
                    for tag in page.tags.values():
                        raw_tags[tag.name] = _sanitize_tag_value(tag.value)
                    if raw_tags:
                        payload["tiff_summary"] = _build_tiff_summary(raw_tags)
                        payload["tiff_tags"] = {
                            k: v for k, v in raw_tags.items()
                            if k not in _TIFF_TAGS_EXCLUDE
                        }

                try:
                    st = os.stat(str(p))
                    payload["file"] = {
                        "size_bytes": st.st_size,
                        "modified": datetime.fromtimestamp(st.st_mtime).isoformat(),
                        "created": datetime.fromtimestamp(st.st_ctime).isoformat(),
                    }
                except Exception:
                    pass

                return JSONResponse(payload)

            except Exception as e_tiff:
                raise HTTPException(
                    status_code=500,
                    detail=f"Failed to read TIFF headers (Pillow: {e_pillow}; tifffile: {e_tiff})",
                )
        else:
            raise HTTPException(status_code=500, detail=f"Failed to read headers: {e_pillow}")
