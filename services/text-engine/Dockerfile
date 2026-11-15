FROM python:3.12-slim
WORKDIR /app
COPY . .
RUN pip install --no-cache-dir .
EXPOSE 8001
CMD ["python", "-m", "mofaregh_text.api", "--host", "0.0.0.0", "--port", "8001"]
