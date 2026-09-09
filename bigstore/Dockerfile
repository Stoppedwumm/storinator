FROM node:24-bookworm-slim AS builder

WORKDIR /app

# Install native build tools for better-sqlite3 compilation
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    make \
    g++ \
    && rm -rf /var/lib/apt/lists/*

COPY package*.json ./
RUN npm install --omit=dev

FROM node:24-bookworm-slim

WORKDIR /app

COPY --from=builder /app/node_modules ./node_modules
COPY . .

# Create internal data directories
RUN mkdir -p /data/databases /data/files

ENV PORT=8080
ENV SQLITE_BIGSTORE_PATH=/data/databases/bigstore.sqlite
ENV BIGSTORE_STORAGE_ROOT=/data/files

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --retries=3 \
  CMD node -e "const http=require('http'); http.get({host:'127.0.0.1',port:8080,path:'/internal/health',headers:{'X-Internal-Service-Token':process.env.INTERNAL_BIGSTORE_SECRET||''}},(r)=>{process.exit(r.statusCode===200?0:1);}).on('error',()=>process.exit(1));"

CMD ["node", "src/server.js"]
