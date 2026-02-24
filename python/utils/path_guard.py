from pathlib import Path
from fastapi import HTTPException

ALLOWED_ROOT = Path("/app/data/sessions").resolve()


def require_safe_path(path: str) -> Path:
    """
    Resolve the given path and ensure it lives under ALLOWED_ROOT.
    Raises HTTPException 400 on path traversal, 404 if file not found.
    """
    try:
        p = Path(path).resolve()
    except Exception:
        raise HTTPException(status_code=400, detail="Invalid path")

    if not str(p).startswith(str(ALLOWED_ROOT)):
        raise HTTPException(status_code=400, detail="Path outside allowed directory")

    if not p.exists():
        raise HTTPException(status_code=404, detail="File not found")

    return p
