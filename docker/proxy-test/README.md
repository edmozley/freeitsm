# Reverse proxy test stack

FreeITSM behind an **nginx reverse proxy that terminates HTTPS**, which is how most
production installs run. **Development only: the passwords are public and the
certificate is self-signed.**

Built for [issue #152](https://github.com/edmozley/freeitsm/issues/152): behind a
proxy like this, the SSO redirect URI came out as `http://`, and Entra refused it
with `AADSTS50011`. Nothing on a WAMP box looks like this setup, which is why it
needs its own stack.

| Service | Port | What it is |
|---|---|---|
| `proxy` | [https://localhost:8453](https://localhost:8453) | nginx 1.27, TLS on, sends `X-Forwarded-Proto` |
| `app` | none | FreeITSM built from this checkout, `TRUST_PROXY_HTTPS=1` |
| `db` | none | MySQL 8.0 seeded from `database/freeitsm.sql` |

The app has no port of its own on purpose. The proxy is the only way in, which is
the only situation in which `TRUST_PROXY_HTTPS` is safe to turn on.

## Run it

From the repo root:

```sh
docker compose -p proxytest -f docker/proxy-test/docker-compose.yml up -d --build
```

The project name `proxytest` keeps it apart from your main stack. Sign in as
`admin` / `freeitsm`. On a fresh database that account must change its password
first, so either do that in the browser or, since this database is throwaway:

```sh
docker exec proxytest-db-1 mysql -ufreeitsm -pfreeitsm freeitsm \
  -e "UPDATE analysts SET must_change_password = 0 WHERE id = 1"
```

## Check it

```sh
sh docker/proxy-test/check.sh [admin-password]
```

It signs in through the proxy and reports every address the app built from the
request: the SSO redirect URI (settings page, help page and the real sign-in
redirect), the API base URL, the webhooks cron URL, and two redirects that
Apache makes itself (`/login.php` to `/login`, and a folder without its slash).
Any that come back `http://` are a failure.

The SSO sign-in check needs a provider with id 1. Google's public discovery
document is enough, because only the redirect is being looked at:

```sh
# System -> Single Sign-On -> Add: issuer https://accounts.google.com,
# any client id and secret, enabled. Then turn single sign-on on.
```

## The negative case

With the flag off, a client-sent `X-Forwarded-Proto` must change nothing, or any
visitor could flip the scheme on a plain-HTTP install:

```sh
TRUST_PROXY_HTTPS=0 docker compose -p proxytest -f docker/proxy-test/docker-compose.yml up -d app
sh docker/proxy-test/check.sh    # every line should now FAIL (say http)
docker compose -p proxytest -f docker/proxy-test/docker-compose.yml up -d app
```

## Throw it away

```sh
docker compose -p proxytest -f docker/proxy-test/docker-compose.yml down -v
```
