#!/bin/sh
# Runs when the container starts. Writes the browser's runtime settings (config.js) and the nginx site configuration from
# LMS_API_URL, so the same image can point at any API. The API's own address is also the only outside place the page may
# connect to (Content-Security-Policy connect-src).
set -eu

api_url="${LMS_API_URL:-http://localhost:8080/api}"
api_origin=$(printf '%s' "$api_url" | sed -E 's#^(https?://[^/]+).*#\1#')

case "$api_url" in
  http://*|https://*) ;;
  *) echo "LMS_API_URL must start with http:// or https:// (got: $api_url)" >&2; exit 1 ;;
esac

# Only characters that are safe inside a JavaScript string and an nginx header value are allowed.
case "$api_url$api_origin" in
  *[\'\"\\\;\ \$\`]*) echo "LMS_API_URL contains characters that are not allowed" >&2; exit 1 ;;
esac

printf 'window.__LMS_CONFIG__ = { apiUrl: "%s" }\n' "$api_url" > /usr/share/nginx/html/config.js
sed "s#__API_ORIGIN__#${api_origin}#g" /etc/lms/default.conf.template > /etc/nginx/conf.d/default.conf
