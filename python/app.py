from fastapi import FastAPI

from routers import fits, nef, xisf, image, forecast

app = FastAPI(title="Astropy FITS helper")


@app.get("/health")
def health():
    return {"status": "ok"}


app.include_router(fits.router)
app.include_router(nef.router)
app.include_router(xisf.router)
app.include_router(image.router)
app.include_router(forecast.router)
