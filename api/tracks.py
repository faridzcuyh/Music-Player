#!/usr/bin/env python3
import http.server
import json
import os
import urllib.parse

MUSIC_DIR = '/home/faridz/Music'
API_PORT = 9001
AUDIO_EXTENSIONS = ('.mp3', '.flac', '.wav', '.m4a', '.ogg', '.aac', '.wma', '.opus')


class APIHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path == '/api/tracks':
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.send_header('Cache-Control', 'no-cache')
            self.end_headers()

            try:
                files = sorted([
                    f for f in os.listdir(MUSIC_DIR)
                    if f.lower().endswith(AUDIO_EXTENSIONS)
                ], key=str.lower)
                self.wfile.write(json.dumps(files).encode())
            except Exception as e:
                self.wfile.write(json.dumps({'error': str(e)}).encode())
        else:
            self.send_response(404)
            self.end_headers()
            self.wfile.write(b'Not Found')

    def log_message(self, format, *args):
        print(f"[API] {args[0]} {args[1]} {args[2]}")


if __name__ == '__main__':
    server = http.server.HTTPServer(('127.0.0.1', API_PORT), APIHandler)
    print(f"[API] Music API running on http://127.0.0.1:{API_PORT}")
    print(f"[API] Scanning: {MUSIC_DIR}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[API] Shutting down")
        server.server_close()
