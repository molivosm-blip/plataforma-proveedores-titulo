# Imagen del frontend: compila con Vite y sirve con nginx.
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Variables de Vite: se fijan al compilar.
ARG VITE_PROVEEDORES_API_URL=http://localhost:8000/v1
ARG VITE_LOCAL_DEMO=true
ARG VITE_AZURE_CLIENT_ID=
ARG VITE_AZURE_TENANT_ID=
ARG VITE_AZURE_API_SCOPE=
ENV VITE_PROVEEDORES_API_URL=$VITE_PROVEEDORES_API_URL \
    VITE_LOCAL_DEMO=$VITE_LOCAL_DEMO \
    VITE_AZURE_CLIENT_ID=$VITE_AZURE_CLIENT_ID \
    VITE_AZURE_TENANT_ID=$VITE_AZURE_TENANT_ID \
    VITE_AZURE_API_SCOPE=$VITE_AZURE_API_SCOPE
RUN npm run build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
