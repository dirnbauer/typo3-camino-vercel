#!/usr/bin/env python3

import http.client
import os
import socket
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


LISTEN_PORT = int(os.environ.get("VERCEL_SOLR_PUBLIC_PORT", os.environ.get("PORT", "80")))
TARGET_PORT = int(os.environ.get("SOLR_PORT_LISTEN", os.environ.get("SOLR_INTERNAL_PORT", "8983")))
READY_FILE = os.environ.get("SOLR_READY_FILE", "/tmp/typo3-solr-ready")
READY_TIMEOUT = float(os.environ.get("TYPO3_SOLR_PROXY_READY_TIMEOUT", "90"))
UPSTREAM_TIMEOUT = float(os.environ.get("TYPO3_SOLR_PROXY_UPSTREAM_TIMEOUT", "60"))

HOP_BY_HOP_HEADERS = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailers",
    "transfer-encoding",
    "upgrade",
}


class WaitingSolrProxy(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "typo3-vercel-solr-proxy"

    def log_message(self, message, *args):
        print(
            "%s - - [%s] %s"
            % (self.client_address[0], self.log_date_time_string(), message % args),
            flush=True,
        )

    def do_HEAD(self):
        self.handle_proxy_request()

    def do_GET(self):
        self.handle_proxy_request()

    def do_POST(self):
        self.handle_proxy_request()

    def do_PUT(self):
        self.handle_proxy_request()

    def do_DELETE(self):
        self.handle_proxy_request()

    def wait_for_ready(self):
        deadline = time.monotonic() + READY_TIMEOUT
        while time.monotonic() <= deadline:
            if os.path.exists(READY_FILE):
                return True
            time.sleep(0.25)
        return False

    def send_plain(self, status, body, retry_after=None):
        payload = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        if retry_after is not None:
            self.send_header("Retry-After", str(retry_after))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(payload)

    def handle_proxy_request(self):
        if self.path in ("/_health", "/healthz"):
            status = "ready" if os.path.exists(READY_FILE) else "warming"
            self.send_plain(200, status + "\n")
            return

        if not self.wait_for_ready():
            self.send_plain(503, "TYPO3 Solr is still warming up.\n", retry_after=3)
            return

        content_length = int(self.headers.get("Content-Length", "0") or "0")
        request_body = self.rfile.read(content_length) if content_length > 0 else None

        headers = {
            key: value
            for key, value in self.headers.items()
            if key.lower() not in HOP_BY_HOP_HEADERS
        }
        headers["Host"] = f"127.0.0.1:{TARGET_PORT}"

        connection = None
        try:
            connection = http.client.HTTPConnection(
                "127.0.0.1",
                TARGET_PORT,
                timeout=UPSTREAM_TIMEOUT,
            )
            connection.request(
                self.command,
                self.path,
                body=request_body,
                headers=headers,
            )
            response = connection.getresponse()
            response_body = response.read()
        except (ConnectionError, TimeoutError, OSError, socket.timeout) as error:
            self.send_plain(502, f"TYPO3 Solr upstream error: {error}\n", retry_after=3)
            return
        finally:
            if connection is not None:
                try:
                    connection.close()
                except Exception:
                    pass

        self.send_response(response.status, response.reason)
        for key, value in response.getheaders():
            if key.lower() in HOP_BY_HOP_HEADERS or key.lower() == "content-length":
                continue
            self.send_header(key, value)
        self.send_header("Content-Length", str(len(response_body)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(response_body)


def main():
    ThreadingHTTPServer.allow_reuse_address = True
    server = ThreadingHTTPServer(("0.0.0.0", LISTEN_PORT), WaitingSolrProxy)
    print(
        "Forwarding Vercel port %s to Solr port %s through readiness proxy"
        % (LISTEN_PORT, TARGET_PORT),
        flush=True,
    )
    server.serve_forever()


if __name__ == "__main__":
    main()
