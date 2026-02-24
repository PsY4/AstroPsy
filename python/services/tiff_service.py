import numpy as np
import tifffile as tiff
from PIL import Image
from fastapi import HTTPException

from services.stretch import to_rgb_image


def _sniff_tiff_magic(path: str) -> bool:
    """Return True if the file starts with TIFF or BigTIFF magic bytes."""
    try:
        with open(path, "rb") as f:
            magic = f.read(4)
        return magic in (b"II*\x00", b"MM\x00*", b"II+\x00", b"MM\x00+")
    except Exception:
        return False


def open_tiff_as_image(path: str) -> Image.Image:
    """
    Open a TIFF file as an RGB 8-bit PIL Image.
    Tries Pillow first, falls back to tifffile for BigTIFF / float32 / compressed formats.
    """
    try:
        im = Image.open(path)
        if getattr(im, "n_frames", 1) > 1:
            im.seek(0)
        if im.mode in ("I;16", "I;16B", "I;16L", "I;16S", "I", "F", "I;32F"):
            return to_rgb_image(np.array(im))
        if im.mode != "RGB":
            im = im.convert("RGB")
        return im
    except Exception as e_pillow:
        try:
            with tiff.TiffFile(path) as tf:
                arr = tf.pages[0].asarray()
                return to_rgb_image(arr)
        except Exception as e_tiff:
            raise HTTPException(
                status_code=415,
                detail=f"Cannot read TIFF: Pillow='{e_pillow}'; tifffile='{e_tiff}'.",
            )
