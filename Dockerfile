# Container image for Vercel (Container framework preset) or any Docker host.
# Configuration comes from environment variables (DIFY_API_BASE, DIFY_API_KEY, ...),
# set in the Vercel project settings; there is no .env file in the image.
FROM php:8.3-cli-alpine

WORKDIR /app
COPY . .
RUN mkdir -p storage

# Vercel's edge sets X-Real-IP / X-Forwarded-For; trust them for per-visitor rate limits.
ENV TRUST_PROXY=true \
    PHP_CLI_SERVER_WORKERS=8

# Vercel sends traffic to port 80 unless PORT is set in the project settings.
EXPOSE 80
CMD ["sh", "-c", "exec php -S 0.0.0.0:${PORT:-80} -t public"]
