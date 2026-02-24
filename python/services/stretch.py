import numpy as np
from PIL import Image


def _percentile_asinh_8bit(arr: np.ndarray, p_low=0.1, p_high=99.9, asinh_soft=10.0) -> np.ndarray:
    """Stretch a float array to 8-bit using percentile clipping + asinh compression."""
    arr = arr.astype(np.float32)
    lo = np.percentile(arr, p_low)
    hi = np.percentile(arr, p_high)
    if hi <= lo:
        hi = lo + 1.0
    arr = np.clip((arr - lo) / (hi - lo), 0.0, 1.0)
    arr = np.arcsinh(asinh_soft * arr) / np.arcsinh(asinh_soft)
    return np.clip(arr * 255.0 + 0.5, 0, 255).astype(np.uint8)


def stretch_channel(arr: np.ndarray, stretch: str, bp_pct: float, wp_pct: float) -> np.ndarray:
    """Apply custom stretch to a float array → uint8.
    stretch: 'linear' | 'sqrt' | 'log' | 'asinh'
    bp_pct/wp_pct: percentile clipping (e.g. 0.1 / 99.9)
    """
    arr = arr.astype(np.float32)
    lo = np.percentile(arr, bp_pct)
    hi = np.percentile(arr, wp_pct)
    if hi <= lo:
        hi = lo + 1.0
    arr = np.clip((arr - lo) / (hi - lo), 0.0, 1.0)
    if stretch == 'log':
        arr = np.log1p(arr * 9.0) / np.log1p(9.0)
    elif stretch == 'sqrt':
        arr = np.sqrt(arr)
    elif stretch == 'asinh':
        soft = 10.0
        arr = np.arcsinh(soft * arr) / np.arcsinh(soft)
    # else: linear (no transform)
    return np.clip(arr * 255.0 + 0.5, 0, 255).astype(np.uint8)


def to_rgb_image(arr: np.ndarray) -> Image.Image:
    """Convert a 2D or 3D float array to a stretched RGB PIL Image."""
    if arr.ndim == 2:
        ch = _percentile_asinh_8bit(arr)
        return Image.fromarray(ch, mode="L").convert("RGB")
    if arr.ndim == 3:
        chs = [_percentile_asinh_8bit(arr[..., c]) for c in range(min(arr.shape[2], 3))]
        while len(chs) < 3:
            chs.append(chs[0])
        return Image.merge("RGB", [Image.fromarray(c) for c in chs])
    # Fallback
    return Image.fromarray(_percentile_asinh_8bit(arr), mode="L").convert("RGB")


def resize_keep_ratio(im: Image.Image, w: int) -> Image.Image:
    """Resize PIL Image to width w, preserving aspect ratio."""
    h = max(1, int(im.height * (w / im.width)))
    return im.resize((w, h), Image.LANCZOS)
