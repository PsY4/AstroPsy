FROM python:3.11-slim

RUN apt-get update && apt-get install -y build-essential libcfitsio-dev && rm -rf /var/lib/apt/lists/*

WORKDIR /app/alpaca_server

COPY alpaca_server.py .
COPY requirements.txt .
COPY start.sh .

RUN pip install --no-cache-dir -r requirements.txt
RUN chmod +x /app/alpaca_server/start.sh

EXPOSE 11111
EXPOSE 32227/udp
CMD ["/app/alpaca_server/start.sh"]