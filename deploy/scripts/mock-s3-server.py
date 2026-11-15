#!/usr/bin/env python3
"""
MOS-GOV · 本地模拟 S3 兼容端点（仅用于 sync-to-r2.sh 测试，不做真实鉴权）

用法:
    python3 mock-s3-server.py [host] [port]

支持:
    PUT    /<bucket>/<key>   存储对象（记录 content-length）
    GET    /<bucket>/<key>   取回对象
    HEAD   /<bucket>/<key>   返回 Content-Length

注意:
    * 这是测试替身，不实现 AWS SigV4，配合 sync-to-r2.sh --no-sign 使用。
    * 数据存于临时目录，进程退出即丢弃；绝不接触真实 R2 / 云账户。
"""
import sys
import os
import re
from http.server import BaseHTTPRequestHandler, HTTPServer

HOST = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 9000
STORE = {}


class H(BaseHTTPRequestHandler):
    def _path(self):
        m = re.match(r"^/([^/]+)/(.+)$", self.path)
        if not m:
            return None, None
        return m.group(1), m.group(2)

    def do_PUT(self):
        bucket, key = self._path()
        if bucket is None:
            self.send_response(400); self.end_headers(); return
        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length)
        STORE[(bucket, key)] = body
        self.send_response(200)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def do_GET(self):
        bucket, key = self._path()
        obj = STORE.get((bucket, key)) if bucket else None
        if obj is None:
            self.send_response(404); self.end_headers(); return
        self.send_response(200)
        self.send_header("Content-Length", str(len(obj)))
        self.send_header("Content-Type", "application/octet-stream")
        self.end_headers()
        self.wfile.write(obj)

    def do_HEAD(self):
        bucket, key = self._path()
        obj = STORE.get((bucket, key)) if bucket else None
        if obj is None:
            self.send_response(404); self.end_headers(); return
        self.send_response(200)
        self.send_header("Content-Length", str(len(obj)))
        self.end_headers()

    def log_message(self, *a):
        pass  # 静默


if __name__ == "__main__":
    print(f"mock-s3 listening on http://{HOST}:{PORT} (data in memory, no auth)")
    HTTPServer((HOST, PORT), H).serve_forever()
