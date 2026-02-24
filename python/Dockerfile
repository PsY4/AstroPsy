FROM python:3.12-slim

RUN apt-get update && apt-get install -y build-essential libcfitsio-dev && rm -rf /var/lib/apt/lists/*

WORKDIR /app/python
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://localhost:8000/health')"

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
