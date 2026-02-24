import numpy as np
from astropy.io import fits


def first_image_hdu(hdul):
    """Return the first HDU that contains image data, or None."""
    for h in hdul:
        if isinstance(h, (fits.PrimaryHDU, fits.ImageHDU, fits.CompImageHDU)) and h.data is not None:
            return h
    return None


def to_js9_safe_hdu(hdu):
    """
    Convert an HDU to a JS9-safe float32 PrimaryHDU:
    - take first plane of cubes
    - replace NaN/Inf
    - strip BZERO/BSCALE/BLANK to avoid re-scaling
    """
    data = hdu.data
    if data is None:
        return hdu
    if data.ndim > 2:
        data = data[0]
    data = np.asarray(data, dtype=np.float64)
    data = np.nan_to_num(
        data,
        nan=0.0,
        posinf=np.finfo(np.float32).max,
        neginf=np.finfo(np.float32).min,
    ).astype(np.float32)

    hdr = hdu.header.copy()
    for k in ("BZERO", "BSCALE", "BLANK"):
        if k in hdr:
            del hdr[k]
    return fits.PrimaryHDU(data=data, header=hdr)
