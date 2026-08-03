import http.server
import socketserver
import json
import os

PORT = 8080
DIR = os.path.dirname(os.path.abspath(__file__))

class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIR, **kwargs)

    def do_GET(self):
        if self.path == '/api/tracks':
            mp3_files = [f for f in os.listdir(DIR) if f.lower().endswith('.mp3')]
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps(mp3_files).encode())
        else:
            super().do_GET()

with socketserver.TCPServer(("", PORT), Handler) as httpd:
    print(f"Server running at http://localhost:{PORT}")
    print(f"Music folder: {DIR}")
    print("Press Ctrl+C to stop")
    httpd.serve_forever()