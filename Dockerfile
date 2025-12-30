# PHP application stage
FROM marcelorodrigo/freshguard:latest

# Install and build frontend assets
RUN npm ci && npm run build

# Expose port
EXPOSE 80