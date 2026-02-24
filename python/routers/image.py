import io
import os
import traceback
from datetime import datetime

import tifffile as tiff
from fastapi import APIRouter, HTTPException
from fastapi.responses import JSONResponse, Response
from PIL import Image, ExifTags, TiffImagePlugin, ImageFile

from services.stretch import to_rgb_image, resize_keep_ratio
from services.tiff_service import open_tiff_as_image, _sniff_tiff_magic
from utils.path_guard import require_safe_path

ImageFile.LOAD_TRUNCATED_IMAGES = True
Image.MAX_IMAGE_PIXELS = None

router = APIRouter()

ALLOWED_IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".tif", ".tiff"}


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
                    info["dpi_x"], info["dpi_y"] = dpi
                else:
                    info["dpi"] = dpi
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
                        if isinstance(value, bytes):
                            try:
                                value = value.decode(errors="replace")
                            except Exception:
                                value = str(value)
                        exif_map[tag] = value
            except Exception:
                pass
            if exif_map:
                payload["exif"] = exif_map

            if isinstance(im, TiffImagePlugin.TiffImageFile):
                try:
                    tiff_tags = {}
                    for tag, value in im.tag_v2.items():
                        tag_name = TiffImagePlugin.TAGS_V2.get(tag, str(tag))
                        tiff_tags[tag_name] = value
                    if tiff_tags:
                        payload["tiff_tags"] = tiff_tags
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

                    tiff_tags = {}
                    for tag in page.tags.values():
                        val = tag.value
                        if isinstance(val, (bytes, bytearray)) and len(val) > 256:
                            tiff_tags[tag.name] = f"<{len(val)} bytes>"
                        else:
                            tiff_tags[tag.name] = val
                    if tiff_tags:
                        payload["tiff_tags"] = tiff_tags

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
