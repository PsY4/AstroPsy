import numpy as np


def _json_safe(obj):
    """Recursively convert any object to a JSON-serializable type."""
    if isinstance(obj, dict):
        return {str(_json_safe(k)): _json_safe(v) for k, v in obj.items()}
    if isinstance(obj, (list, tuple, set)):
        return [_json_safe(x) for x in obj]
    if isinstance(obj, np.generic):
        return obj.item()
    if isinstance(obj, np.ndarray):
        return obj.tolist()
    if isinstance(obj, (np.dtype,)):
        return str(obj)
    if isinstance(obj, (bytes, bytearray, memoryview)):
        try:
            return bytes(obj).decode("utf-8")
        except UnicodeDecodeError:
            return bytes(obj).hex()
    return obj


def _to_float(x):
    try:
        return float(x)
    except (TypeError, ValueError):
        return None
